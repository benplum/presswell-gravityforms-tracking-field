<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Gravity Forms integration hooks.
 */
trait PWTSR_Gravity_Forms_Trait {

  /**
   * Initialize Gravity Forms hooks when available.
   */
  public function maybe_bootstrap_gravity_forms() {
    if ( ! class_exists( 'GFForms' ) ) {
      return;
    }

    GF_Fields::register( new PWTSR_Gravity_Forms_Field() );

    add_action( 'gform_editor_js_set_default_values', [ $this, 'output_editor_defaults_js' ] );
    add_action( 'gform_editor_js', [ $this, 'output_editor_guard_js' ] );
    add_action( 'gform_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ], 10, 2 );
    add_filter( 'gform_pre_form_editor_save', [ $this, 'enforce_single_tracking_field' ] );
    add_filter( 'gform_pre_render', [ $this, 'enforce_single_tracking_field' ], 5 );
    add_filter( 'gform_pre_validation', [ $this, 'enforce_single_tracking_field' ], 5 );
    add_filter( 'gform_pre_submission_filter', [ $this, 'enforce_single_tracking_field' ], 5 );
    add_filter( 'gform_pre_submission_filter', [ $this, 'sanitize_tracking_submission_values' ], 10 );
    add_filter( 'gform_admin_pre_render', [ $this, 'enforce_single_tracking_field' ], 5 );
    add_filter( 'gform_custom_merge_tags', [ $this, 'register_tracking_merge_tags' ], 10, 4 );
    add_filter( 'gform_replace_merge_tags', [ $this, 'replace_tracking_merge_tags' ], 10, 7 );
  }

  /**
   * Output default settings for the field within the form editor.
   */
  public function output_editor_defaults_js() {
    $keys = $this->service->get_tracking_keys( 'gravityforms' );
    ?>
    case 'presswell_transceiver':
      field.label = '<?php echo esc_js( __( 'Tracking', PWTSR::TEXT_DOMAIN ) ); ?>';
      field.labelPlacement = 'hidden_label';
      field.description = '<?php echo esc_js( __( 'Captures UTM and click attribution parameters for the current visitor.', PWTSR::TEXT_DOMAIN ) ); ?>';
      field.inputs = [];
      <?php foreach ( $keys as $index => $key ) : ?>
      field.inputs.push( new Input( field.id + '.<?php echo esc_js( $index + 1 ); ?>', '<?php echo esc_js( $key ); ?>', '<?php echo esc_js( $key ); ?>' ) );
      <?php endforeach; ?>
    break;
    <?php
  }

  /**
   * Register and enqueue front-end assets when needed.
   *
   * @param array $form Gravity Forms form array.
   */
  public function maybe_enqueue_assets( $form, $is_ajax = false ) {
    if ( ! $this->form_contains_tracking_field( $form ) ) {
      return;
    }

    $this->enqueue_tracking_script( PWTSR::ADAPTER_GRAVITY_FORMS );
    $this->enqueue_visibility_styles();
  }

  /**
   * Output CSS that removes the tracking field from layout flow.
   */
  private function enqueue_visibility_styles() {
    if ( $this->styles_enqueued ) {
      return;
    }

    $debug_mode = false;
    if ( function_exists( 'presswell_tracking_signal_relay' ) ) {
      $plugin = presswell_tracking_signal_relay();
      if ( $plugin && method_exists( $plugin, 'should_show_debug_styles' ) ) {
        $debug_mode = $plugin->should_show_debug_styles();
      }
    }

    if ( $debug_mode ) {
      $this->styles_enqueued = true;
      return;
    }

    wp_register_style( PWTSR::ASSET_HANDLE_STYLE, false, [], PWTSR::VERSION );
    wp_enqueue_style( PWTSR::ASSET_HANDLE_STYLE );

    $selectors = [
      '.gfield--type-' . PWTSR::FIELD_TYPE,
      '.gfield--type-' . str_replace( '_', '-', PWTSR::FIELD_TYPE ),
    ];

    $css = implode( ',', $selectors ) . '{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important;}';
    wp_add_inline_style( PWTSR::ASSET_HANDLE_STYLE, $css );

    $this->styles_enqueued = true;
  }

  /**
   * Determine if the current form includes the tracking field.
   *
   * @param array $form Gravity Forms form array.
   *
   * @return bool
   */
  private function form_contains_tracking_field( $form ) {
    if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
      return false;
    }

    foreach ( $form['fields'] as $field ) {
      $field_type = $this->extract_field_type( $field );
      if ( $field_type && PWTSR::FIELD_TYPE === $field_type ) {
        return true;
      }
    }

    return false;
  }

  /**
   * Ensure only one tracking field exists per form.
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

      if ( $field_type && PWTSR::FIELD_TYPE === $field_type ) {
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
   * Sanitize posted tracking values before entry save.
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
      if ( PWTSR::FIELD_TYPE !== $field_type ) {
        continue;
      }

      $field_id = $this->extract_field_id( $field );
      if ( ! $field_id ) {
        continue;
      }

      $index = 1;
      foreach ( $this->service->get_tracking_keys( 'gravityforms' ) as $key ) {
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

        $_POST[ $posted_key ] = $this->service->sanitize_tracking_value( $key, $raw );
        $index++;
      }
    }

    return $form;
  }

  /**
   * Block editors from inserting multiple tracking fields.
   */
  public function output_editor_guard_js() {
    ?>
    <script type="text/javascript">
      (function (window, document) {
        var slug = '<?php echo esc_js( PWTSR::FIELD_TYPE ); ?>';
        var warning = '<?php echo esc_js( __( 'Only one Tracking field can be added per form.', PWTSR::TEXT_DOMAIN ) ); ?>';

        function guardSingleField() {
          if (typeof window.StartAddField !== 'function' || window.StartAddField._presswellTransceiverGuard) {
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

          window.StartAddField._presswellTransceiverGuard = true;
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
   * Extract field type from an object or array field definition.
   *
   * @param mixed $field Gravity Forms field.
   *
   * @return string
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
   * Extract numeric field id from object or array field definitions.
   *
   * @param mixed $field Gravity Forms field.
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
   * Register custom merge tags for tracking values.
   *
   * @param array $merge_tags Existing merge tags.
   *
   * @return array
   */
  public function register_tracking_merge_tags( $merge_tags, $form_id, $fields, $element_id ) {
    if ( ! is_array( $merge_tags ) ) {
      $merge_tags = [];
    }

    $merge_tags[] = [
      'label' => __( 'Tracking: All Values', PWTSR::TEXT_DOMAIN ),
      'tag'   => '{tracking:all}',
    ];

    foreach ( $this->service->get_tracking_keys( 'gravityforms' ) as $key ) {
      $merge_tags[] = [
        'label' => sprintf( __( 'Tracking: %s', PWTSR::TEXT_DOMAIN ), $key ),
        'tag'   => '{tracking:' . $key . '}',
      ];
    }

    return $merge_tags;
  }

  /**
   * Replace custom tracking merge tags with entry values.
   *
   * Supported tags:
   * - {tracking:all}
   * - {tracking:key_name}
   * - {tracking_values}
   *
   * @param string $text       Text with merge tags.
   * @param array  $form       Current form array.
   * @param array  $entry      Current entry array.
   * @param bool   $url_encode Whether to url encode replacement values.
   * @param bool   $esc_html   Whether to escape HTML in replacement values.
   * @param bool   $nl2br      Whether to convert newlines to br tags.
   * @param string $format     Output format.
   *
   * @return string
   */
  public function replace_tracking_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
    if ( ! is_string( $text ) || false === strpos( $text, '{tracking' ) ) {
      return $text;
    }

    $pairs = $this->get_gravity_tracking_pairs_from_entry( $form, $entry );
    if ( empty( $pairs ) ) {
      $text = str_replace( [ '{tracking:all}' ], '', $text );

      return preg_replace( '/\{tracking:([a-z0-9_\-]+)\}/i', '', $text );
    }

    $all_value = implode( ', ', array_map(
      static function( $key, $value ) {
        return $key . '=' . $value;
      },
      array_keys( $pairs ),
      array_values( $pairs )
    ) );

    $text = str_replace( '{tracking:all}', $this->format_merge_tag_value( $all_value, $url_encode, $esc_html, $nl2br, $format ), $text );

    return preg_replace_callback(
      '/\{tracking:([a-z0-9_\-]+)\}/i',
      function( $matches ) use ( $pairs, $url_encode, $esc_html, $nl2br, $format ) {
        $key = isset( $matches[1] ) ? strtolower( (string) $matches[1] ) : '';
        if ( 'all' === $key ) {
          return $matches[0];
        }

        $value = isset( $pairs[ $key ] ) ? $pairs[ $key ] : '';

        return $this->format_merge_tag_value( $value, $url_encode, $esc_html, $nl2br, $format );
      },
      $text
    );
  }

  /**
   * Resolve tracking key/value pairs from the Gravity entry.
   *
   * @param array $form  Current form array.
   * @param array $entry Current entry array.
   *
   * @return array
   */
  private function get_gravity_tracking_pairs_from_entry( $form, $entry ) {
    if ( ! is_array( $form ) || empty( $form['fields'] ) || ! is_array( $form['fields'] ) || ! is_array( $entry ) ) {
      return [];
    }

    $tracking_field = null;
    foreach ( $form['fields'] as $field ) {
      if ( PWTSR::FIELD_TYPE === $this->extract_field_type( $field ) ) {
        $tracking_field = $field;
        break;
      }
    }

    if ( null === $tracking_field ) {
      return [];
    }

    $field_id = $this->extract_field_id( $tracking_field );
    if ( ! $field_id ) {
      return [];
    }

    $pairs = [];
    $index = 1;
    foreach ( $this->service->get_tracking_keys( 'gravityforms' ) as $key ) {
      $input_id = sprintf( '%d.%d', $field_id, $index );
      $value    = rgar( $entry, $input_id );
      $clean    = $this->service->sanitize_tracking_value( $key, $value );
      if ( '' !== $clean ) {
        $pairs[ $key ] = $clean;
      }
      $index++;
    }

    return $pairs;
  }

  /**
   * Apply Gravity merge-tag format options to a value.
   *
   * @param string $value      Raw value.
   * @param bool   $url_encode Whether to url encode value.
   * @param bool   $esc_html   Whether to escape HTML.
   * @param bool   $nl2br      Whether to convert newlines to br.
   * @param string $format     Output format.
   *
   * @return string
   */
  private function format_merge_tag_value( $value, $url_encode, $esc_html, $nl2br, $format ) {
    $value = (string) $value;

    if ( $url_encode ) {
      $value = rawurlencode( $value );
    }

    if ( $esc_html ) {
      $value = esc_html( $value );
    }

    if ( $nl2br ) {
      $value = nl2br( $value );
    }

    return $value;
  }
}
