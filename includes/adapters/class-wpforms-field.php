<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'PWTSR_WPForms_Field' ) ) {
  /**
   * WPForms compound field that captures tracking values as explicit subinputs.
   */
  class PWTSR_WPForms_Field extends WPForms_Field {

    /**
     * Initialize field metadata.
     */
    public function init() {
      $this->name     = esc_html__( 'Tracking', PWTSR::TEXT_DOMAIN );
      $this->keywords = esc_html__( 'tracking, utm, attribution, campaign', PWTSR::TEXT_DOMAIN );
      $this->type     = PWTSR::FIELD_TYPE;
      $this->icon     = 'pwtsr-radar-icon';
      $this->order    = 310;
      $this->group    = 'fancy';

      $this->default_settings = [
        'label_hide' => '1',
      ];

      add_filter( 'wpforms_field_properties_' . $this->type, [ $this, 'field_properties' ], 10, 3 );
    }

    /**
     * Field options panel inside the builder.
     *
     * @param array $field Field information.
     */
    public function field_options( $field ) {
      $this->field_option(
        'basic-options',
        $field,
        [
          'markup' => 'open',
        ]
      );

      $this->field_option( 'label', $field );

      $this->field_option(
        'basic-options',
        $field,
        [
          'markup' => 'close',
        ]
      );

      $this->field_option(
        'advanced-options',
        $field,
        [
          'markup' => 'open',
        ]
      );

      $this->field_option( 'css', $field );
      $this->field_option( 'label_hide', $field );

      $this->field_option(
        'advanced-options',
        $field,
        [
          'markup' => 'close',
        ]
      );
    }

    /**
     * Builder preview output.
     *
     * @param array $field Field information.
     */
    public function field_preview( $field ) {
      $this->field_preview_option( 'label', $field );

      echo '<p class="wpforms-description">' . esc_html__( 'Captures UTM and click attribution parameters for the current visitor.', PWTSR::TEXT_DOMAIN ) . '</p>';
    }

    /**
     * Build subinput properties for each tracking key.
     *
     * @param array $properties Field properties.
     * @param array $field      Field configuration.
     * @param array $form_data  Form configuration.
     *
     * @return array
     */
    public function field_properties( $properties, $field, $form_data ) {
      $properties = (array) $properties;

      if ( ! is_admin() ) {
        if ( ! isset( $properties['label'] ) || ! is_array( $properties['label'] ) ) {
          $properties['label'] = [];
        }

        $properties['label']['value'] = esc_html__( 'Signals', PWTSR::TEXT_DOMAIN );
      } else {
        if ( ! isset( $properties['label'] ) || ! is_array( $properties['label'] ) ) {
          $properties['label'] = [];
        }

        $properties['label']['hidden'] = true;
        $properties['label']['value']  = '';
      }

      if ( empty( $field['id'] ) || empty( $form_data['id'] ) ) {
        return $properties;
      }

      $form_id  = absint( $form_data['id'] );
      $field_id = wpforms_validate_field_id( $field['id'] );

      if ( ! isset( $properties['inputs'] ) || ! is_array( $properties['inputs'] ) ) {
        $properties['inputs'] = [];
      }

      unset( $properties['inputs']['primary'] );

      foreach ( $this->get_tracking_keys() as $key ) {
        $properties['inputs'][ $key ] = [
          'attr'  => [
            'name'  => "wpforms[fields][{$field_id}][{$key}]",
            'value' => '',
          ],
          'class' => [
            'presswell-wpforms-transceiver-input',
          ],
          'data'  => [
            'presswell-transceiver' => $key,
          ],
          'id'    => "wpforms-{$form_id}-field_{$field_id}-{$key}",
        ];
      }

      return $properties;
    }

    /**
     * Field output on the frontend.
     *
     * @param array $field      Field configuration.
     * @param array $deprecated Deprecated parameter.
     * @param array $form_data  Form configuration.
     */
    public function field_display( $field, $deprecated, $form_data ) {
      if ( is_admin() ) {
        return;
      }

      if ( empty( $field['id'] ) ) {
        return;
      }

      $field_id = wpforms_validate_field_id( $field['id'] );
      $inputs   = '';

      foreach ( $this->get_tracking_keys() as $key ) {
        $input_name = "wpforms[fields][{$field_id}][{$key}]";
        $input_id   = 'presswell-wpforms-' . $field_id . '-' . sanitize_html_class( $key );

        if ( ! empty( $field['properties']['inputs'][ $key ]['attr']['name'] ) ) {
          $input_name = (string) $field['properties']['inputs'][ $key ]['attr']['name'];
        }

        if ( ! empty( $field['properties']['inputs'][ $key ]['id'] ) ) {
          $input_id = (string) $field['properties']['inputs'][ $key ]['id'];
        }

        $inputs .= $this->render_transceiver_input_markup( $key, $input_name, $input_id );
      }

      if ( '' === $inputs ) {
        return;
      }

      echo PWTSR_Transceiver_Markup::render_wrapper(
        'presswell-transceiver presswell-wpforms-transceiver',
        PWTSR::ADAPTER_WPFORMS,
        $inputs,
        $this->is_debug_field_mode()
      );
    }

    /**
     * Format and sanitize value for entry storage.
     *
     * @param int   $field_id     Field id.
     * @param mixed $field_submit Submitted field value.
     * @param array $form_data    Form settings.
     */
    public function format( $field_id, $field_submit, $form_data ) {
      $field_id = wpforms_validate_field_id( $field_id );
      $name     = isset( $form_data['fields'][ $field_id ]['label'] ) ? $form_data['fields'][ $field_id ]['label'] : '';

      $pairs = [];
      foreach ( $this->get_tracking_keys() as $key ) {
        $raw = is_array( $field_submit ) && isset( $field_submit[ $key ] ) ? $field_submit[ $key ] : '';

        if ( is_array( $raw ) ) {
          continue;
        }

        $clean = $this->get_service()->sanitize_tracking_value( $key, $raw );
        if ( '' !== $clean ) {
          $pairs[ $key ] = $clean;
        }
      }

      $lines = [];
      foreach ( $pairs as $key => $value ) {
        $lines[] = $key . ': ' . $value;
      }

      $formatted = [
        'name'     => sanitize_text_field( $name ),
        'value'    => implode( "\n", $lines ),
        'id'       => $field_id,
        'type'     => $this->type,
        'tracking' => $pairs,
      ];

      foreach ( $this->get_tracking_keys() as $key ) {
        $formatted[ $key ] = isset( $pairs[ $key ] ) ? $pairs[ $key ] : '';
      }

      wpforms()->obj( 'process' )->fields[ $field_id ] = $formatted;
    }

    /**
     * Resolve the plugin service.
     *
     * @return PWTSR_Tracking_Service
     */
    private function get_service() {
      if ( function_exists( 'presswell_tracking_signal_relay' ) ) {
        $plugin = presswell_tracking_signal_relay();
        if ( $plugin && method_exists( $plugin, 'get_service' ) ) {
          $service = $plugin->get_service();
          if ( $service instanceof PWTSR_Tracking_Service ) {
            return $service;
          }
        }
      }

      return new PWTSR_Tracking_Service();
    }

    /**
     * Resolve tracking keys for this field.
     *
     * @return string[]
     */
    private function get_tracking_keys() {
      return $this->get_service()->get_tracking_keys( PWTSR::ADAPTER_WPFORMS );
    }

    /**
     * Determine whether debug field mode is enabled for current user/request.
     *
     * @return bool
     */
    private function is_debug_field_mode() {
      if ( function_exists( 'presswell_tracking_signal_relay' ) ) {
        $plugin = presswell_tracking_signal_relay();
        if ( $plugin && method_exists( $plugin, 'should_show_debug_styles' ) ) {
          return (bool) $plugin->should_show_debug_styles();
        }
      }

      return false;
    }

    /**
     * Render a key-mapped hidden/debug-friendly input.
     *
     * @param string $key   Tracking key.
     * @param string $name  Input name.
     * @param string $id    Input id.
     * @param string $value Current value.
     *
     * @return string
     */
    private function render_transceiver_input_markup( $key, $name, $id, $value = '' ) {
      if ( $this->is_debug_field_mode() ) {
        return sprintf(
          '<div class="presswell-debug-field-row"><label class="presswell-debug-field-label" for="%1$s">%2$s</label><input type="text" id="%1$s" name="%3$s" value="%4$s" readonly="readonly" data-presswell-transceiver="%5$s" /></div>',
          esc_attr( $id ),
          esc_html( $key ),
          esc_attr( $name ),
          esc_attr( $value ),
          esc_attr( $key )
        );
      }

      return sprintf(
        '<input type="hidden" id="%1$s" name="%2$s" value="%3$s" data-presswell-transceiver="%4$s" />',
        esc_attr( $id ),
        esc_attr( $name ),
        esc_attr( $value ),
        esc_attr( $key )
      );
    }
  }

}
