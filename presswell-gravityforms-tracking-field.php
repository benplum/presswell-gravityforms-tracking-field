<?php
/**
 * Plugin Name: Presswell Tracking Field for Gravity Forms
 * Description: Capture UTM and click tracking parameters across a visitor session.
 * Author: Presswell
 * Version: 1.0.0
 * Plugin URI: http://wordpress.org/plugins/
 * Author URI: http://presswell.co
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: presswell-gf-tracking-field
 * 
 * @package Presswell Tracking Field for Gravity Forms
 * @author Presswell
 *
 * Presswell Tracking Field for Gravity Forms is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * Presswell Tracking Field for Gravity Forms is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Presswell Tracking Field for Gravity Forms. If not, see <http://www.gnu.org/licenses/>.
 */

// Test Params
// ?utm_source=google&utm_medium=cpc&utm_campaign=winter_launch&utm_content=cta_banner&utm_term=snow_boots&gclid=Cj0KCQiAzbi-ABCD1234&fbclid=fb.9876543210XYZ&msclkid=MSCLKID123456&ttclid=TTCLID-987654&landing_page=https%3A%2F%2Fexample.com%2Fwinter-sale&landing_query=%3Fref%3Dpartner&referrer=https%3A%2F%2Fpartner-site.com

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'Presswell_GF_Tracking_Field' ) ) {

  /**
     * Bootstrap container for all tracking field features.
   */
  final class Presswell_GF_Tracking_Field {

    const VERSION        = '1.0.0';
    const FIELD_TYPE     = 'presswell_gumshoe';
    const SCRIPT_HANDLE  = 'presswell-gf-gumshoe-js';
    const STYLE_HANDLE   = 'presswell-gf-gumshoe-css';
    const STORAGE_KEY    = 'gfGumshoe';
    const TTL_SECONDS    = 3600; // 86400;
    const MAX_VALUE_LENGTH = 1024;
    const PLUGIN_FILE    = __FILE__;

    /**
     * Singleton instance reference.
     *
     * @var Presswell_GF_Tracking_Field|null
     */
    private static $instance = null;

    /**
     * Tracks whether assets have been registered.
     *
     * @var bool
     */
    private static $assets_registered = false;

    /**
     * Tracks whether CSS has been output.
     *
     * @var bool
     */
    private static $styles_enqueued = false;

    /**
     * List of tracking keys mirrored on the client and stored with entries.
     *
     * @var string[]
     */
    private static $tracking_keys = [
      'utm_source',
      'utm_medium',
      'utm_campaign',
      'utm_content',
      'utm_term',
      'gclid',
      'fbclid',
      'msclkid',
      'ttclid',
      'landing_page',
      'landing_query',
      'referrer',
    ];

    /**
     * Return the shared plugin instance.
     *
     * @return Presswell_GF_Tracking_Field
     */
    public static function instance() {
      if ( null === self::$instance ) {
        self::$instance = new self();
      }

      return self::$instance;
    }

    /**
     * Wire hooks.
     */
    protected function __construct() {
      add_action( 'plugins_loaded', [ $this, 'maybe_bootstrap' ], 20 );
    }

    /**
     * Prevent cloning the singleton.
     */
    public function __clone() {}

    /**
     * Prevent unserializing the singleton.
     */
    public function __wakeup() {}

    /**
     * Initialize components when Gravity Forms is available.
     */
    public function maybe_bootstrap() {
      if ( ! class_exists( 'GFForms' ) ) {
        return;
      }

      require_once __DIR__ . '/includes/class-gf-field-presswell-tracking.php';

      GF_Fields::register( new GF_Field_Presswell_Tracking() );

      add_action( 'gform_editor_js_set_default_values', [ $this, 'output_editor_defaults_js' ] );
      add_action( 'gform_editor_js', [ $this, 'output_editor_guard_js' ] );
      add_action( 'gform_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ], 10, 2 );
      add_filter( 'gform_pre_form_editor_save', [ $this, 'enforce_single_tracking_field' ] );
      add_filter( 'gform_pre_render', [ $this, 'enforce_single_tracking_field' ], 5 );
      add_filter( 'gform_pre_validation', [ $this, 'enforce_single_tracking_field' ], 5 );
      add_filter( 'gform_pre_submission_filter', [ $this, 'enforce_single_tracking_field' ], 5 );
      add_filter( 'gform_pre_submission_filter', [ $this, 'sanitize_tracking_submission_values' ], 10 );
      add_filter( 'gform_admin_pre_render', [ $this, 'enforce_single_tracking_field' ], 5 );
    }

    /**
     * Output default settings for the field within the form editor.
     */
    public function output_editor_defaults_js() {
      $keys = Presswell_GF_Tracking_Field::get_tracking_keys();
      ?>
      case 'presswell_gumshoe':
        field.label = '<?php echo esc_js( __( 'Tracking', 'presswell-gf-tracking-field' ) ); ?>';
        field.labelPlacement = 'hidden_label';
        field.description = '<?php echo esc_js( __( 'Captures UTM and click attribution parameters for the current visitor.', 'presswell-gf-tracking-field' ) ); ?>';
        field.inputs = [];
        <?php foreach ( $keys as $index => $key ) : ?>
        field.inputs.push( new Input( field.id + '.<?php echo esc_js( $index + 1 ); ?>', '<?php echo esc_js( $key ); ?>', '<?php echo esc_js( $key ); ?>' ) );
        <?php endforeach; ?>
      break;
      <?php
    }

    /**
     * Register and enqueue the front-end tracking script when needed.
     */
    public function maybe_enqueue_assets( $form, $is_ajax ) {
      if ( ! $this->form_contains_tracking_field( $form ) ) {
        return;
      }

      $this->register_assets();

      wp_enqueue_script( self::SCRIPT_HANDLE );
      $this->enqueue_visibility_styles();

      static $localized = false;
      if ( ! $localized ) {
        wp_localize_script(
          self::SCRIPT_HANDLE,
          'presswellGFGumshoeConfig',
          [
            'storageKey'  => self::STORAGE_KEY,
            'ttl'         => self::get_ttl_seconds(),
            'gumshoeKeys' => self::get_tracking_keys(),
          ]
        );
        $localized = true;
      }
    }

    /**
     * Register the JavaScript asset once per request.
     */
    private function register_assets() {
      if ( self::$assets_registered ) {
        return;
      }

      wp_register_script(
        self::SCRIPT_HANDLE,
        plugins_url( 'assets/js/gumshoe.js', __FILE__ ),
        [],
        self::VERSION,
        true
      );

      self::$assets_registered = true;
    }

    /**
     * Output CSS that completely removes the field from layout flow.
     */
    private function enqueue_visibility_styles() {
      if ( self::$styles_enqueued ) {
        return;
      }

      wp_register_style( self::STYLE_HANDLE, false, [], self::VERSION );
      wp_enqueue_style( self::STYLE_HANDLE );

      $css = '.gfield--type-presswell_gumshoe{position:fixed!important;height:0!important;width:0!important;overflow:hidden!important;pointer-events:none!important;}';
      wp_add_inline_style( self::STYLE_HANDLE, $css );

      self::$styles_enqueued = true;
    }

    /**
     * Determine if the current form includes the tracking field.
     */
    private function form_contains_tracking_field( $form ) {
      if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
        return false;
      }

      foreach ( $form['fields'] as $field ) {
        $field_type = $this->extract_field_type( $field );
        if ( $field_type && self::FIELD_TYPE === $field_type ) {
          return true;
        }
      }

      return false;
    }

    /**
     * Expose the list of tracking keys.
     */
    public static function get_tracking_keys() {
      return apply_filters( 'presswell_gf_tracking_keys', self::$tracking_keys );
    }

    /**
     * Expose the storage TTL in seconds for client consumption.
     */
    public static function get_ttl_seconds() {
      $ttl = (int) apply_filters( 'presswell_gf_tracking_ttl', self::TTL_SECONDS );
      return $ttl > 0 ? $ttl : self::TTL_SECONDS;
    }

    /**
     * Ensure only one gumshoe field exists per form by removing additional instances.
     *
     * @param array $form Current Gravity Forms form array.
     *
     * @return array
     */
    public function enforce_single_tracking_field( $form ) {
      if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
        return $form;
      }

      $filtered = [];
      $found    = false;

      foreach ( $form['fields'] as $field ) {
        $field_type = $this->extract_field_type( $field );

        if ( $field_type && self::FIELD_TYPE === $field_type ) {
          if ( $found ) {
            continue;
          }
          $found = true;
        }

        $filtered[] = $field;
      }

      if ( count( $filtered ) !== count( $form['fields'] ) ) {
        $form['fields'] = array_values( $filtered );
      }

      return $form;
    }

    /**
     * Sanitize and clamp posted tracking input values before Gravity Forms saves the entry.
     *
     * @param array $form Current Gravity Forms form array.
     *
     * @return array
     */
    public function sanitize_tracking_submission_values( $form ) {
      if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) || empty( $_POST ) || ! is_array( $_POST ) ) {
        return $form;
      }

      foreach ( $form['fields'] as $field ) {
        $field_type = $this->extract_field_type( $field );
        if ( self::FIELD_TYPE !== $field_type ) {
          continue;
        }

        $field_id = $this->extract_field_id( $field );
        if ( ! $field_id ) {
          continue;
        }

        $index = 1;
        foreach ( self::get_tracking_keys() as $key ) {
          $posted_key = sprintf( 'input_%d_%d', $field_id, $index );
          if ( ! isset( $_POST[ $posted_key ] ) ) {
            $index++;
            continue;
          }

          $raw = wp_unslash( $_POST[ $posted_key ] );
          if ( is_array( $raw ) ) {
            unset( $_POST[ $posted_key ] );
            $index++;
            continue;
          }

          $_POST[ $posted_key ] = self::sanitize_tracking_value( $key, $raw );
          $index++;
        }
      }

      return $form;
    }

    /**
     * Block editors from inserting multiple gumshoe fields.
     */
    public function output_editor_guard_js() {
      ?>
      <script type="text/javascript">
        (function (window, document) {
          var slug = '<?php echo esc_js( self::FIELD_TYPE ); ?>';
          var warning = '<?php echo esc_js( __( 'Only one Tracking field can be added per form.', 'presswell-gf-tracking-field' ) ); ?>';

          function guardSingleField() {
            if (typeof window.StartAddField !== 'function' || window.StartAddField._presswellGumshoeGuard) {
              return typeof window.StartAddField === 'function';
            }

            var originalStartAddField = window.StartAddField;
            window.StartAddField = function (type) {
              if (type === slug && typeof window.GetFieldsByType === 'function') {
                var existing = window.GetFieldsByType([slug]) || [];
                if (existing.length) {
                  window.alert(warning);
                  return;
                }
              }

              return originalStartAddField.apply(this, arguments);
            };

            window.StartAddField._presswellGumshoeGuard = true;
            return true;
          }

          if (!guardSingleField()) {
            document.addEventListener('DOMContentLoaded', guardSingleField);
          }
        })(window, document);
      </script>
      <?php
    }

    /**
     * Extract the type identifier from either an object or array field definition.
     */
    private function extract_field_type( $field ) {
      if ( is_object( $field ) && isset( $field->type ) ) {
        return $field->type;
      }

      if ( is_array( $field ) && isset( $field['type'] ) ) {
        return $field['type'];
      }

      return '';
    }

    /**
     * Extract the numeric field id from a field object or array.
     *
     * @param mixed $field Gravity Forms field object or array.
     *
     * @return int
     */
    private function extract_field_id( $field ) {
      if ( is_object( $field ) && isset( $field->id ) ) {
        return absint( $field->id );
      }

      if ( is_array( $field ) && isset( $field['id'] ) ) {
        return absint( $field['id'] );
      }

      return 0;
    }

    /**
     * Sanitize a tracking value and enforce max length.
     *
     * @param string $key Tracking key.
     * @param mixed  $value Tracking value.
     *
     * @return string
     */
    public static function sanitize_tracking_value( $key, $value ) {
      if ( ! is_scalar( $value ) ) {
        return '';
      }

      $clean = sanitize_textarea_field( (string) $value );
      if ( '' === $clean ) {
        return '';
      }

      $clean = self::truncate_value( $clean );
      if ( '' === $clean ) {
        return '';
      }

      if ( in_array( $key, [ 'landing_page', 'referrer' ], true ) ) {
        $clean = esc_url_raw( $clean );
        return self::truncate_value( $clean );
      }

      return $clean;
    }

    /**
     * Clamp string values to the plugin max length.
     *
     * @param string $value Raw value.
     *
     * @return string
     */
    private static function truncate_value( $value ) {
      $value = (string) $value;
      if ( '' === $value ) {
        return '';
      }

      if ( function_exists( 'mb_substr' ) ) {
        return mb_substr( $value, 0, self::MAX_VALUE_LENGTH );
      }

      return substr( $value, 0, self::MAX_VALUE_LENGTH );
    }
  }
}

if ( ! function_exists( 'presswell_gf_tracking_field' ) ) {
  /**
   * Helper to access the singleton instance from procedural code.
   *
   * @return Presswell_GF_Tracking_Field
   */
  function presswell_gf_tracking_field() {
    return Presswell_GF_Tracking_Field::instance();
  }
}

presswell_gf_tracking_field();
