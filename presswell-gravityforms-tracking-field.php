<?php
/**
 * Plugin Name: Presswell Tracking Field for Gravity Forms
 * Description: Capture UTM and click tracking parameters across a visitor session.
 * Version: 0.1.0
 * Author: Ben Plum
 * License: GPLv2 or later
 * Text Domain: presswell-gf-tracking-field
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'Presswell_GF_Tracking_Field' ) ) {

  /**
   * Bootstrapper for the tracking field plugin.
   */
  final class Presswell_GF_Tracking_Field {

    const VERSION        = '0.1.0';
    const FIELD_TYPE     = 'presswell_gumshoe';
    const SCRIPT_HANDLE  = 'presswell-gf-gumshoe-js';
    const STYLE_HANDLE   = 'presswell-gf-gumshoe-css';
    const STORAGE_KEY    = 'gfGumshoe';
    const TTL_SECONDS    = 3600; // 86400;
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
    private static $tracking_keys = array(
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
    );

    /**
     * Initiate the singleton.
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
    private function __construct() {
      add_action( 'plugins_loaded', array( $this, 'maybe_bootstrap' ), 20 );
    }

    /**
     * Initialize components when Gravity Forms is available.
     */
    public function maybe_bootstrap() {
      if ( ! class_exists( 'GFForms' ) ) {
        return;
      }

      require_once __DIR__ . '/includes/class-gf-field-presswell-tracking.php';

      GF_Fields::register( new GF_Field_Presswell_Tracking() );

      add_action( 'gform_editor_js_set_default_values', array( $this, 'output_editor_defaults_js' ) );
      add_action( 'gform_editor_js', array( $this, 'output_editor_guard_js' ) );
      add_action( 'gform_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ), 10, 2 );
      add_filter( 'gform_pre_form_editor_save', array( $this, 'enforce_single_tracking_field' ) );
      add_filter( 'gform_pre_render', array( $this, 'enforce_single_tracking_field' ), 5 );
      add_filter( 'gform_pre_validation', array( $this, 'enforce_single_tracking_field' ), 5 );
      add_filter( 'gform_pre_submission_filter', array( $this, 'enforce_single_tracking_field' ), 5 );
      add_filter( 'gform_admin_pre_render', array( $this, 'enforce_single_tracking_field' ), 5 );
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
          array(
            'storageKey'  => self::STORAGE_KEY,
            'ttl'         => self::get_ttl_seconds(),
            'gumshoeKeys' => self::get_tracking_keys(),
          )
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
        array(),
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

      wp_register_style( self::STYLE_HANDLE, false, array(), self::VERSION );
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

      $filtered = array();
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
  }
}

Presswell_GF_Tracking_Field::instance();
