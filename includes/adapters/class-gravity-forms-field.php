<?php
/**
 * Gravity Forms field that stores session tracking parameters.
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( class_exists( 'GF_Field' ) && ! class_exists( 'PWTSR_Gravity_Forms_Field' ) ) {

  class PWTSR_Gravity_Forms_Field extends GF_Field {

    /**
     * Field type identifier registered with Gravity Forms.
     *
     * @var string
     */
    public $type = PWTSR::FIELD_TYPE;

    /**
     * Hide the field label by default.
     *
     * @var string
     */
    public $labelPlacement = 'hidden_label';

    /**
     * Resolve the shared tracking service.
     *
     * @return PWTSR_Tracking_Service
     */
    private function service() {
      return presswell_tracking_signal_relay()->get_service();
    }

    /**
     * Title displayed within the form editor panel.
     *
     * @return string
     */
    public function get_form_editor_field_title() {
      return esc_html__( 'Tracking', PWTSR::TEXT_DOMAIN );
    }

    /**
     * Provide a custom radar icon for the form editor button.
     *
     * @return string
     */
    public function get_form_editor_field_icon() {
      return plugin_dir_url( Presswell_Tracking_Signal_Relay::PLUGIN_FILE ) . 'assets/svg/radar.svg';
    }

    /**
     * Settings available when editing the field in the form editor.
     *
     * @return string[]
     */
    public function get_form_editor_field_settings() {
      return [
        'label_setting',
        'description_setting',
        'css_class_setting',
      ];
    }

    /**
     * Button registration details for the form editor panel.
     *
     * @return array
     */
    public function get_form_editor_button() {
      return [
        'group' => 'advanced_fields',
        'text'  => esc_html__( 'Tracking', PWTSR::TEXT_DOMAIN ),
      ];
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
     * Define the individual inputs saved alongside entries.
     *
     * @return array
     */
    public function get_inputs() {
      $keys   = $this->service()->get_tracking_keys( 'gravityforms' );
      $inputs = [];

      if ( empty( $keys ) ) {
        return $inputs;
      }

      $index = 1;
      foreach ( $keys as $key ) {
        $inputs[] = [
          'id'       => sprintf( '%d.%d', $this->id, $index ),
          'label'    => $key,
          'name'     => $key,
          'isHidden' => true,
        ];
        $index++;
      }

      return $inputs;
    }

    /**
     * Ensure Gravity Forms always treats the field as multi-input.
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
     * @param array        $form  Current form object.
     * @param string|array $value Current value.
     * @param array|null   $entry Entry data when in admin context.
     *
     * @return string
     */
    public function get_field_input( $form, $value = '', $entry = null ) {
      $form_id   = absint( rgar( $form, 'id' ) );
      $inputs    = $this->get_inputs();
      $input_tag = [];
      $debug_mode = $this->is_debug_field_mode();

      if ( empty( $inputs ) ) {
        return '';
      }

      foreach ( $inputs as $input ) {
        $input_id   = (string) $input['id'];
        $custom_id  = sprintf( 'input_%d_%s', $form_id, str_replace( '.', '_', $input_id ) );
        $field_name = 'input_' . str_replace( '.', '_', $input_id );
        $current    = '';
        $key_name   = isset( $input['name'] ) ? (string) $input['name'] : '';

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

        $current = $this->service()->sanitize_tracking_value( $key_name, $current );

        if ( $debug_mode ) {
          $input_tag[] = sprintf(
            '<div class="presswell-debug-field-row"><label class="presswell-debug-field-label" for="%1$s">%2$s</label><input type="text" id="%1$s" name="%3$s" value="%4$s" readonly="readonly" data-presswell-transceiver="%5$s" /></div>',
            esc_attr( $custom_id ),
            esc_html( $key_name ),
            esc_attr( $field_name ),
            esc_attr( $current ),
            esc_attr( $input['name'] )
          );
          continue;
        }

        $input_tag[] = sprintf(
          '<input type="hidden" id="%1$s" name="%2$s" value="%3$s" data-presswell-transceiver="%4$s" />',
          esc_attr( $custom_id ),
          esc_attr( $field_name ),
          esc_attr( $current ),
          esc_attr( $input['name'] )
        );
      }

      $wrapper_classes = 'presswell-transceiver presswell-gravity-transceiver presswell-transceiver-field ginput_container';
      if ( $debug_mode ) {
        $wrapper_classes .= ' gform-theme__disable-reset';
      }

      return PWTSR_Transceiver_Markup::render_wrapper(
        $wrapper_classes,
        'gravity',
        implode( '', $input_tag ),
        $debug_mode
      );
    }

    /**
     * Whether debug field mode is enabled for current user/request.
     *
     * @return bool
     */
    private function is_debug_field_mode() {
      if ( class_exists( 'GFCommon' ) && GFCommon::is_form_editor() ) {
        return false;
      }

      if ( ! function_exists( 'presswell_tracking_signal_relay' ) ) {
        return false;
      }

      $plugin = presswell_tracking_signal_relay();

      return $plugin && method_exists( $plugin, 'should_show_debug_styles' ) && $plugin->should_show_debug_styles();
    }

    /**
     * Suppress descriptive text on public forms while preserving editor/admin output.
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
        $icon_url = esc_url( plugin_dir_url( Presswell_Tracking_Signal_Relay::PLUGIN_FILE ) . 'assets/svg/radar.svg' );
        $label    = esc_html__( 'Tracking', PWTSR::TEXT_DOMAIN );

        $badge = sprintf(
          '<div class="presswell-transceiver-editor-badge" style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-weight:600;"><img src="%1$s" alt="%2$s" width="20" height="20" style="display:block;" /><span>%2$s</span></div>',
          $icon_url,
          $label
        );

        return sprintf( '<div class="presswell-transceiver-editor-preview">%1$s%2$s</div>', $badge, $content );
      }

      if ( GFCommon::is_entry_detail() ) {
        return parent::get_field_content( $value, $lead_id, $form_id );
      }

      $original_label       = $this->label;
      $original_description = $this->description;

      $this->label       = 'Signals';
      $this->description = '';

      $content = parent::get_field_content( $value, $lead_id, $form_id );

      $this->label       = $original_label;
      $this->description = $original_description;

      return $content;
    }

    /**
     * Display all stored tracking pairs inside the entry detail view.
     *
     * @param mixed  $value    Entry value.
     * @param string $currency Entry currency.
     * @param bool   $use_text Return as text.
     * @param string $format   Output format.
     * @param string $media    Output media.
     *
     * @return string
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

      $items = [];
      foreach ( $pairs as $key => $val ) {
        $items[] = sprintf( '<li><strong>%s:</strong> %s</li>', esc_html( $key ), esc_html( $val ) );
      }

      return sprintf( '<ul class="presswell-utm-entry-detail">%s</ul>', implode( '', $items ) );
    }

    /**
     * Surface a concise summary on the entries list table.
     *
     * @param mixed $value   Entry value.
     * @param array $entry   Entry payload.
     * @param mixed $field_id Field id.
     * @param mixed $columns Columns array.
     * @param mixed $form    Form payload.
     *
     * @return string
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
     * Convert stored entry values into key/value pairs.
     *
     * @param mixed $raw Raw entry value passed by Gravity Forms.
     *
     * @return array
     */
    private function get_tracking_value_pairs( $raw ) {
      $keys = $this->service()->get_tracking_keys( 'gravityforms' );
      if ( empty( $keys ) ) {
        return [];
      }

      $source = $this->normalize_value_source( $raw );
      $pairs  = [];

      $index = 1;
      foreach ( $keys as $key ) {
        $input_id = sprintf( '%d.%d', $this->id, $index );
        $value    = rgar( $source, $input_id );
        if ( null === $value && isset( $source[ $key ] ) ) {
          $value = $source[ $key ];
        }

        $pairs[ $key ] = $this->service()->sanitize_tracking_value( $key, $value );
        $index++;
      }

      return $pairs;
    }

    /**
     * Attempt to load full entry array in entry detail context.
     *
     * @return array
     */
    private function get_entry_context_values() {
      if ( isset( $this->entry ) && is_array( $this->entry ) ) {
        return $this->entry;
      }

      if ( ! GFCommon::is_entry_detail() || ! class_exists( 'GFAPI' ) ) {
        return [];
      }

      $entry_id = absint( rgget( 'lid' ) );
      if ( ! $entry_id ) {
        $entry_id = absint( rgget( 'entry' ) );
      }

      if ( ! $entry_id ) {
        return [];
      }

      $entry = GFAPI::get_entry( $entry_id );
      if ( is_wp_error( $entry ) || ! is_array( $entry ) ) {
        return [];
      }

      return $entry;
    }

    /**
     * Normalize serialized or JSON-encoded values into arrays.
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

      return [];
    }

    /**
     * Remove empty values from a key/value map.
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
     * Format pairs as key/value strings.
     *
     * @param array $pairs Key/value map.
     *
     * @return array
     */
    private function format_pairs_as_text( $pairs ) {
      $lines = [];
      foreach ( $pairs as $key => $val ) {
        $lines[] = sprintf( '%s: %s', $key, $val );
      }

      return $lines;
    }

    /**
     * Ensure Gravity Forms stores array data for each input.
     *
     * @return bool
     */
    public function is_value_submission_array() {
      return true;
    }
  }
}
