<?php
/**
 * Gravity Forms field that stores session tracking parameters.
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( class_exists( 'GF_Field' ) && ! class_exists( 'GF_Field_Presswell_Tracking' ) ) {

  class GF_Field_Presswell_Tracking extends GF_Field {

    /**
     * Field type identifier registered with Gravity Forms.
     *
     * @var string
     */
    public $type = Presswell_GF_Tracking_Field::FIELD_TYPE;

    /**
     * Hide the field label by default.
     *
     * @var string
     */
    public $labelPlacement = 'hidden_label';

    /**
     * Title displayed within the form editor panel.
     *
     * @return string
     */
    public function get_form_editor_field_title() {
      return esc_html__( 'Tracking', 'presswell-gf-tracking-field' );
    }

    /**
     * Provide a custom radar icon for the form editor button.
     *
     * @return string
     */
    public function get_form_editor_field_icon() {
      return plugin_dir_url( Presswell_GF_Tracking_Field::PLUGIN_FILE ) . 'assets/svg/radar.svg';
    }

    /**
     * Settings available when editing the field in the form editor.
     *
     * @return string[]
     */
    public function get_form_editor_field_settings() {
      return array(
        'label_setting',
        'description_setting',
        'css_class_setting',
      );
    }

    /**
     * Button registration details for the form editor panel.
     *
     * @return array
     */
    public function get_form_editor_button() {
      return array(
        'group' => 'advanced_fields',
        'text'  => esc_html__( 'Tracking', 'presswell-gf-tracking-field' ),
      );
    }

    /**
     * Disable conditional logic configuration for this field.
     *
     * @return bool
     */
    public function is_conditional_logic_supported() {
      return false;
    }

    /**
     * Define the individual inputs (one per tracking key) saved alongside entries.
     *
     * @return array
     */
    public function get_inputs() {
      $keys   = Presswell_GF_Tracking_Field::get_tracking_keys();
      $inputs = array();

      if ( empty( $keys ) ) {
        return $inputs;
      }

      $index = 1;
      foreach ( $keys as $key ) {
        $inputs[] = array(
          'id'       => sprintf( '%d.%d', $this->id, $index ),
          'label'    => $key,
          'name'     => $key,
          'isHidden' => true,
        );
        $index++;
      }

      return $inputs;
    }

    /**
     * Ensure Gravity Forms always treats the field as multi-input when saving entries.
     *
     * @return array
     */
    public function get_entry_inputs() {
      if ( is_array( $this->inputs ) && ! empty( $this->inputs ) ) {
        return $this->inputs;
      }

      $this->inputs = $this->get_inputs();

      return $this->inputs;
    }

    /**
     * Render hidden inputs that will be populated by the tracking script.
     *
     * @param array      $form  Current form object.
     * @param string|array $value Current value.
     * @param array|null $entry Entry data when in admin context.
     *
     * @return string
     */
    public function get_field_input( $form, $value = '', $entry = null ) {
      $form_id   = absint( rgar( $form, 'id' ) );
      $inputs    = $this->get_inputs();
      $input_tag = array();

      if ( empty( $inputs ) ) {
        return '';
      }

      foreach ( $inputs as $input ) {
        $input_id   = (string) $input['id'];
        $custom_id  = sprintf( 'input_%d_%s', $form_id, str_replace( '.', '_', $input_id ) );
        $field_name = 'input_' . str_replace( '.', '_', $input_id );
        $current    = '';

        if ( is_array( $value ) ) {
          $current = rgar( $value, $input_id );
        } elseif ( is_scalar( $value ) ) {
          $current = $value;
        }

        if ( is_array( $entry ) ) {
          $entry_value = rgar( $entry, $input_id );
          if ( null !== $entry_value ) {
            $current = $entry_value;
          }
        }

        $input_tag[] = sprintf(
          '<input type="hidden" id="%1$s" name="%2$s" value="%3$s" data-presswell-gumshoe="%4$s" />',
          esc_attr( $custom_id ),
          esc_attr( $field_name ),
          esc_attr( $current ),
          esc_attr( $input['name'] )
        );
      }

      $wrapper_classes = array( 'presswell-gumshoe-field', 'ginput_container' );

      return sprintf(
        '<div class="%1$s" style="display:none" aria-hidden="true">%2$s</div>',
        esc_attr( implode( ' ', $wrapper_classes ) ),
        implode( '', $input_tag )
      );
    }

    /**
     * Suppress descriptive text on the public form while allowing it in the editor/admin views.
     *
     * @param string $value   Current value.
     * @param string $lead_id Entry ID when loading existing entries.
     * @param int    $form_id Form ID.
     *
     * @return string
     */
    public function get_field_content( $value, $lead_id = 0, $form_id = 0 ) {
      if ( GFCommon::is_form_editor() ) {
        $content  = parent::get_field_content( $value, $lead_id, $form_id );
        $icon_url = esc_url( plugin_dir_url( Presswell_GF_Tracking_Field::PLUGIN_FILE ) . 'assets/svg/radar.svg' );
        $label    = esc_html__( 'Tracking', 'presswell-gf-tracking-field' );

        $badge = sprintf(
          '<div class="presswell-gumshoe-editor-badge" style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-weight:600;"><img src="%1$s" alt="%2$s" width="20" height="20" style="display:block;" /><span>%2$s</span></div>',
          $icon_url,
          $label
        );

        return sprintf( '<div class="presswell-gumshoe-editor-preview">%1$s%2$s</div>', $badge, $content );
      }

      if ( GFCommon::is_entry_detail() ) {
        return parent::get_field_content( $value, $lead_id, $form_id );
      }

      $original_label       = $this->label;
      $original_description = $this->description;

      $this->label       = 'Gumshoe';
      $this->description = '';

      $content = parent::get_field_content( $value, $lead_id, $form_id );

      $this->label       = $original_label;
      $this->description = $original_description;

      return $content;
    }

    /**
     * Display all stored tracking pairs inside the entry detail view.
     */
    public function get_value_entry_detail( $value, $currency = '', $use_text = false, $format = 'html', $media = 'screen' ) {
      $pairs = $this->filter_empty_pairs( $this->get_tracking_value_pairs( $value ) );

      if ( empty( $pairs ) ) {
        $entry_context = $this->get_entry_context_values();
        if ( ! empty( $entry_context ) ) {
          $pairs = $this->filter_empty_pairs( $this->get_tracking_value_pairs( $entry_context ) );
        }
      }

      if ( empty( $pairs ) ) {
        return '';
      }

      if ( 'text' === $format || 'url' === $format ) {
        $separator = 'text' === $format ? "\n" : ', ';
        return implode( $separator, $this->format_pairs_as_text( $pairs ) );
      }

      $items = array();
      foreach ( $pairs as $key => $val ) {
        $items[] = sprintf( '<li><strong>%s:</strong> %s</li>', esc_html( $key ), esc_html( $val ) );
      }

      return sprintf( '<ul class="presswell-utm-entry-detail">%s</ul>', implode( '', $items ) );
    }

    /**
     * Surface a concise summary on the entries list table.
     */
    public function get_value_entry_list( $value, $entry, $field_id, $columns, $form ) {
      $pairs = $this->get_tracking_value_pairs( $entry );
      $pairs = $this->filter_empty_pairs( $pairs );

      if ( empty( $pairs ) ) {
        return '';
      }

      $summary = array_slice( $this->format_pairs_as_text( $pairs ), 0, 3 );
      return esc_html( implode( ', ', $summary ) );
    }

    /**
     * Convert stored entry values into a key => value map matching the tracking keys.
     *
     * @param array|string $raw Raw entry value passed by Gravity Forms.
     *
     * @return array
     */
    private function get_tracking_value_pairs( $raw ) {
      $keys = Presswell_GF_Tracking_Field::get_tracking_keys();
      if ( empty( $keys ) ) {
        return array();
      }

      $source = $this->normalize_value_source( $raw );
      $pairs  = array();

      $index = 1;
      foreach ( $keys as $key ) {
        $input_id = sprintf( '%d.%d', $this->id, $index );
        $value    = rgar( $source, $input_id );
        if ( null === $value && isset( $source[ $key ] ) ) {
          $value = $source[ $key ];
        }
        $pairs[ $key ] = is_scalar( $value ) ? (string) $value : '';
        $index++;
      }

      return $pairs;
    }

    /**
     * Attempt to load the full entry array when running inside the entry detail screen.
     *
     * @return array
     */
    private function get_entry_context_values() {
      if ( isset( $this->entry ) && is_array( $this->entry ) ) {
        return $this->entry;
      }

      if ( ! GFCommon::is_entry_detail() || ! class_exists( 'GFAPI' ) ) {
        return array();
      }

      $entry_id = absint( rgget( 'lid' ) );
      if ( ! $entry_id ) {
        $entry_id = absint( rgget( 'entry' ) );
      }

      if ( ! $entry_id ) {
        return array();
      }

      $entry = GFAPI::get_entry( $entry_id );
      if ( is_wp_error( $entry ) || ! is_array( $entry ) ) {
        return array();
      }

      return $entry;
    }

    /**
     * Normalize serialized or JSON encoded values into an array.
     *
     * @param mixed $raw Potentially serialized value.
     *
     * @return array
     */
    private function normalize_value_source( $raw ) {
      if ( is_array( $raw ) ) {
        return $raw;
      }

      if ( is_string( $raw ) ) {
        $maybe = json_decode( $raw, true );
        if ( is_array( $maybe ) ) {
          return $maybe;
        }

        $unserialized = maybe_unserialize( $raw );
        if ( is_array( $unserialized ) ) {
          return $unserialized;
        }
      }

      return array();
    }

    /**
     * Remove empty values from the key/value map.
     *
     * @param array $pairs Key/value map.
     *
     * @return array
     */
    private function filter_empty_pairs( $pairs ) {
      return array_filter(
        $pairs,
        static function ( $value ) {
          return $value !== '' && $value !== null;
        }
      );
    }

    /**
     * Format the key/value map into "key: value" strings.
     *
     * @param array $pairs Key/value map.
     *
     * @return array
     */
    private function format_pairs_as_text( $pairs ) {
      $lines = array();
      foreach ( $pairs as $key => $val ) {
        $lines[] = sprintf( '%s: %s', $key, $val );
      }

      return $lines;
    }

    /**
     * Ensure Gravity Forms stores array data for each input.
     *
     * @param array $value Posted value.
     *
     * @return bool
     */
    public function is_value_submission_array() {
      return true;
    }

  }
}
