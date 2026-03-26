<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Formidable integration hooks.
 */
trait PWTSR_Formidable_Trait {

  /**
   * Initialize Formidable hooks when available.
   */
  public function maybe_bootstrap_formidable() {
    if ( ! class_exists( 'FrmAppHelper' ) ) {
      return;
    }

    add_action( 'frm_enqueue_form_scripts', [ $this, 'maybe_enqueue_formidable_assets' ], 20, 1 );
    add_filter( 'frm_available_fields', [ $this, 'register_formidable_transceiver_field_type' ], 20 );
    add_filter( 'frm_get_field_type_class', [ $this, 'register_formidable_transceiver_field_class' ], 20, 2 );
    add_action( 'admin_print_footer_scripts', [ $this, 'print_formidable_radar_icon_symbol' ], 20 );
    add_filter( 'frm_pre_create_entry', [ $this, 'sanitize_formidable_submission_values' ], 20, 1 );
    add_filter( 'frm_display_value_custom', [ $this, 'format_formidable_tracking_display_value' ], 20, 3 );
    add_action( 'frm_show_entry', [ $this, 'render_formidable_tracking_block' ], 20, 1 );
    add_filter( 'frm_helper_shortcodes', [ $this, 'register_formidable_tracking_helper_shortcodes' ], 20, 2 );
    add_filter( 'frm_content', [ $this, 'replace_formidable_tracking_tokens' ], 30, 3 );
  }

  /**
   * Print radar icon symbol for Formidable builder icon <use> references.
   */
  public function print_formidable_radar_icon_symbol() {
    if ( ! is_admin() ) {
      return;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || ! isset( $screen->id ) || false === strpos( (string) $screen->id, 'formidable' ) ) {
      return;
    }

    static $printed = false;
    if ( $printed ) {
      return;
    }
    $printed = true;

    $svg_path = plugin_dir_path( Presswell_Tracking_Signal_Relay::PLUGIN_FILE ) . 'assets/svg/radar.svg';
    if ( ! file_exists( $svg_path ) || ! is_readable( $svg_path ) ) {
      return;
    }

    $svg_markup = file_get_contents( $svg_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    if ( ! is_string( $svg_markup ) || '' === $svg_markup ) {
      return;
    }

    $view_box = '0 0 21 21';
    if ( preg_match( '/viewBox="([^"]+)"/i', $svg_markup, $view_box_match ) ) {
      $view_box = $view_box_match[1];
    }

    $path_data = '';
    if ( preg_match( '/<path\b[^>]*>/i', $svg_markup, $path_tag_match ) ) {
      if ( preg_match( '/\bd\s*=\s*"([^"]+)"/i', $path_tag_match[0], $path_match ) ) {
        $path_data = $path_match[1];
      } elseif ( preg_match( '/\bd\s*=\s*\'([^\']+)\'/i', $path_tag_match[0], $path_match ) ) {
        $path_data = $path_match[1];
      }
    }

    if ( '' === $path_data ) {
      return;
    }

    echo sprintf(
      '<svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false"><symbol id="pwtsr-radar-icon" viewBox="%1$s"><path fill="currentColor" d="%2$s" /></symbol></svg>',
      esc_attr( $view_box ),
      esc_attr( $path_data )
    );
  }

  /**
   * Enqueue front-end tracking script for Formidable forms.
   *
   * @param array $params Formidable form params.
   */
  public function maybe_enqueue_formidable_assets( $params = [] ) {
    $form_id = is_array( $params ) && isset( $params['form_id'] ) ? absint( $params['form_id'] ) : 0;
    if ( ! $this->formidable_form_has_transceiver_field( $form_id ) ) {
      return;
    }

    $this->enqueue_tracking_script( PWTSR::ADAPTER_FORMIDABLE );
  }

  /**
   * Register the Tracking field in Formidable's field picker.
   *
   * @param array $fields Existing field definitions.
   *
   * @return array
   */
  public function register_formidable_transceiver_field_type( $fields ) {
    if ( ! is_array( $fields ) ) {
      $fields = [];
    }

    if ( ! isset( $fields[ PWTSR::FIELD_TYPE ] ) ) {
      $fields[ PWTSR::FIELD_TYPE ] = [
        'name' => __( 'Tracking', PWTSR::TEXT_DOMAIN ),
        'icon' => 'frmfont pwtsr-radar-icon',
      ];
    }

    return $fields;
  }

  /**
   * Map the Presswell Formidable field type to its custom class.
   *
   * @param string $class      Existing class name.
   * @param string $field_type Field type slug.
   *
   * @return string
   */
  public function register_formidable_transceiver_field_class( $class, $field_type ) {
    if ( PWTSR::FIELD_TYPE === $field_type && class_exists( 'PWTSR_Formidable_Field' ) ) {
      return 'PWTSR_Formidable_Field';
    }

    return $class;
  }

  /**
   * Sanitize tracking values before Formidable creates an entry.
   *
   * @param array $values Entry payload.
   *
   * @return array
   */
  public function sanitize_formidable_submission_values( $values ) {
    if ( ! is_array( $values ) ) {
      $values = [];
    }

    if ( ! isset( $values['item_meta'] ) || ! is_array( $values['item_meta'] ) ) {
      $values['item_meta'] = [];
    }

    $form_id   = isset( $values['form_id'] ) ? absint( $values['form_id'] ) : 0;
    $field_ids = $this->get_formidable_transceiver_field_ids( $form_id );
    if ( empty( $field_ids ) ) {
      return $values;
    }

    $posted_tracking = [];
    if ( isset( $values['pwtsr_tracking'] ) && is_array( $values['pwtsr_tracking'] ) ) {
      $posted_tracking = $values['pwtsr_tracking'];
    } elseif ( isset( $_POST['pwtsr_tracking'] ) && is_array( $_POST['pwtsr_tracking'] ) ) {
      $posted_tracking = wp_unslash( $_POST['pwtsr_tracking'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    foreach ( $field_ids as $field_id ) {
      $raw = null;
      if ( isset( $posted_tracking[ $field_id ] ) ) {
        $raw = $posted_tracking[ $field_id ];
      } elseif ( isset( $values['item_meta'][ $field_id ] ) ) {
        $raw = $values['item_meta'][ $field_id ];
      }

      $pairs = $this->normalize_formidable_tracking_pairs( $raw );
      $values['item_meta'][ $field_id ] = $pairs;
    }

    unset( $values['pwtsr_tracking'] );

    return $values;
  }

  /**
   * Render Tracking values as readable lines instead of serialized array output.
   *
   * @param mixed  $value Current display value.
   * @param object $field Field object.
   * @param array  $atts  Display attributes.
   *
   * @return mixed
   */
  public function format_formidable_tracking_display_value( $value, $field, $atts ) {
    if ( ! is_object( $field ) || ! isset( $field->type ) || PWTSR::FIELD_TYPE !== $field->type ) {
      return $value;
    }

    $pairs = $this->normalize_formidable_tracking_pairs( $value );
    if ( empty( $pairs ) ) {
      return '';
    }

    $lines = [];
    foreach ( $pairs as $key => $tracked_value ) {
      $lines[] = $key . ': ' . $tracked_value;
    }

    if ( is_array( $atts ) && ! empty( $atts['html'] ) ) {
      return implode( '<br />', array_map( 'esc_html', $lines ) );
    }

    return implode( "\n", $lines );
  }

  /**
   * Render tracking values in Formidable entry detail view.
   *
   * @param object $entry Formidable entry object.
   */
  public function render_formidable_tracking_block( $entry ) {
    if ( ! is_object( $entry ) || empty( $entry->id ) ) {
      return;
    }

    $pairs = $this->get_formidable_tracking_pairs( (int) $entry->id, isset( $entry->form_id ) ? (int) $entry->form_id : 0 );
    if ( empty( $pairs ) ) {
      return;
    }

    echo '<div class="frm_grid_container frm-with-margin">';
    echo '<h3 class="hndle"><span>' . esc_html__( 'Tracking', PWTSR::TEXT_DOMAIN ) . '</span></h3>';
    echo '<table class="widefat striped"><tbody>';

    foreach ( $pairs as $key => $value ) {
      echo '<tr>';
      echo '<th scope="row" style="width:220px;">' . esc_html( $key ) . '</th>';
      echo '<td>' . esc_html( $value ) . '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
  }

  /**
   * Register tracking shortcodes in Formidable's helper insert-code panel.
   *
   * @param array $entry_shortcodes Helper shortcodes.
   * @param bool  $settings_tab     Whether helper panel is shown in settings tab.
   *
   * @return array
   */
  public function register_formidable_tracking_helper_shortcodes( $entry_shortcodes, $settings_tab ) {
    if ( ! is_array( $entry_shortcodes ) ) {
      $entry_shortcodes = [];
    }

    if ( ! isset( $entry_shortcodes['tracking_all'] ) ) {
      $entry_shortcodes['tracking_all'] = __( 'Tracking: All Signals', PWTSR::TEXT_DOMAIN );
    }

    // if ( ! isset( $entry_shortcodes['tracking_values'] ) ) {
    //   $entry_shortcodes['tracking_values'] = __( 'Tracking: All Signals (Alias)', PWTSR::TEXT_DOMAIN );
    // }

    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FORMIDABLE ) as $key ) {
      $shortcode = 'tracking_' . $key;
      if ( isset( $entry_shortcodes[ $shortcode ] ) ) {
        continue;
      }

      $entry_shortcodes[ $shortcode ] = sprintf(
        /* translators: %s tracking key name. */
        __( 'Tracking: %s', PWTSR::TEXT_DOMAIN ),
        $key
      );
    }

    return $entry_shortcodes;
  }

  /**
   * Replace tracking tokens in Formidable content pipelines.
   *
   * Supported tokens:
   * - [tracking_all], [tracking_values], {tracking_all}, {tracking_values}
   * - [tracking_<key>], {tracking_<key>} e.g. [tracking_utm_source]
   *
   * @param string            $content Content string.
   * @param int|object|string $form    Form object/id.
   * @param int|object|string $entry   Entry object/id.
   *
   * @return string
   */
  public function replace_formidable_tracking_tokens( $content, $form, $entry = false ) {
    if ( ! is_string( $content ) || false === strpos( $content, 'tracking_' ) ) {
      return $content;
    }

    $entry_object = $this->resolve_formidable_entry( $entry );
    if ( ! is_object( $entry_object ) || empty( $entry_object->id ) ) {
      return $content;
    }

    $pairs = $this->get_formidable_tracking_pairs( (int) $entry_object->id, isset( $entry_object->form_id ) ? (int) $entry_object->form_id : 0 );

    $all_lines = [];
    foreach ( $pairs as $key => $value ) {
      $all_lines[] = $key . ': ' . $value;
    }
    $all_value = implode( "\n", $all_lines );

    $content = str_replace( [ '[tracking_all]', '[tracking_values]', '{tracking_all}', '{tracking_values}' ], $all_value, $content );

    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FORMIDABLE ) as $key ) {
      $value   = isset( $pairs[ $key ] ) ? $pairs[ $key ] : '';
      $content = str_replace(
        [ '[tracking_' . $key . ']', '{tracking_' . $key . '}' ],
        $value,
        $content
      );
    }

    return $content;
  }

  /**
   * Resolve Formidable entry objects from mixed entry values.
   *
   * @param int|object|string $entry Entry reference.
   *
   * @return object|null
   */
  private function resolve_formidable_entry( $entry ) {
    if ( is_object( $entry ) && ! empty( $entry->id ) ) {
      return $entry;
    }

    if ( ! $entry || ! is_numeric( $entry ) || ! class_exists( 'FrmEntry' ) ) {
      return null;
    }

    $entry_object = FrmEntry::getOne( (int) $entry, true );

    return is_object( $entry_object ) ? $entry_object : null;
  }

  /**
   * Fetch tracking pairs stored against an entry.
   *
   * @param int $entry_id Entry id.
   *
   * @return array
   */
  private function get_formidable_tracking_pairs( $entry_id, $form_id = 0 ) {
    if ( ! class_exists( 'FrmEntryMeta' ) ) {
      return [];
    }

    foreach ( $this->get_formidable_transceiver_field_ids( $form_id ) as $field_id ) {
      $pairs = $this->normalize_formidable_tracking_pairs( FrmEntryMeta::get_entry_meta_by_field( $entry_id, $field_id ) );
      if ( ! empty( $pairs ) ) {
        return $pairs;
      }
    }

    return [];
  }

  /**
   * Determine if a Formidable form includes the Presswell Tracking field.
   *
   * @param int $form_id Form id.
   *
   * @return bool
   */
  private function formidable_form_has_transceiver_field( $form_id ) {
    return ! empty( $this->get_formidable_transceiver_field_ids( $form_id ) );
  }

  /**
   * Return Tracking field ids present in a Formidable form.
   *
   * @param int $form_id Form id.
   *
   * @return int[]
   */
  private function get_formidable_transceiver_field_ids( $form_id ) {
    $form_id = absint( $form_id );
    if ( ! $form_id || ! class_exists( 'FrmField' ) ) {
      return [];
    }

    $ids    = [];
    $fields = FrmField::get_all_types_in_form( $form_id, PWTSR::FIELD_TYPE );
    if ( empty( $fields ) ) {
      return [];
    }

    foreach ( $fields as $field ) {
      if ( is_object( $field ) && ! empty( $field->id ) ) {
        $ids[] = absint( $field->id );
      }
    }

    return array_values( array_filter( array_unique( $ids ) ) );
  }

  /**
   * Normalize and sanitize submitted/saved Formidable tracking payload.
   *
   * @param mixed $raw Raw meta value.
   *
   * @return array
   */
  private function normalize_formidable_tracking_pairs( $raw ) {
    if ( is_string( $raw ) ) {
      $decoded = json_decode( $raw, true );
      if ( is_array( $decoded ) ) {
        $raw = $decoded;
      } else {
        $raw = maybe_unserialize( $raw );
      }
    }

    if ( is_object( $raw ) ) {
      $raw = (array) $raw;
    }

    if ( ! is_array( $raw ) ) {
      return [];
    }

    $pairs = [];
    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FORMIDABLE ) as $key ) {
      if ( ! isset( $raw[ $key ] ) || is_array( $raw[ $key ] ) || is_object( $raw[ $key ] ) ) {
        continue;
      }

      $clean = $this->service->sanitize_tracking_value( $key, $raw[ $key ] );
      if ( '' !== $clean ) {
        $pairs[ $key ] = $clean;
      }
    }

    return $pairs;
  }
}
