<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Formidable integration hooks.
 */
trait PWTSR_Formidable_Trait {

  /**
   * Stores sanitized tracking values until the entry id is available.
   *
   * @var array
   */
  private $pending_formidable_tracking = [];

  /**
   * Initialize Formidable hooks when available.
   */
  public function maybe_bootstrap_formidable() {
    if ( ! class_exists( 'FrmAppHelper' ) ) {
      return;
    }

    add_action( 'frm_enqueue_form_scripts', [ $this, 'maybe_enqueue_formidable_assets' ], 20, 1 );
    add_action( 'frm_entry_form', [ $this, 'render_formidable_tracking_inputs' ], 20, 3 );
    add_filter( 'frm_pre_create_entry', [ $this, 'sanitize_formidable_submission_values' ], 20, 1 );
    add_action( 'frm_after_create_entry', [ $this, 'persist_formidable_tracking_values' ], 20, 3 );
    add_action( 'frm_show_entry', [ $this, 'render_formidable_tracking_block' ], 20, 1 );
    add_filter( 'frm_helper_shortcodes', [ $this, 'register_formidable_tracking_helper_shortcodes' ], 20, 2 );
    add_filter( 'frm_content', [ $this, 'replace_formidable_tracking_tokens' ], 30, 3 );
  }

  /**
   * Enqueue front-end tracking script for Formidable forms.
   *
   * @param array $params Formidable form params.
   */
  public function maybe_enqueue_formidable_assets( $params = [] ) {
    $this->enqueue_tracking_script( PWTSR::ADAPTER_FORMIDABLE );
  }

  /**
   * Inject hidden tracking inputs into Formidable form markup.
   *
    * @param mixed  $form        Formidable form model.
    * @param string $form_action Current form action.
    * @param array  $errors      Validation errors.
   */
  public function render_formidable_tracking_inputs( $form, $form_action, $errors ) {
    $inputs = [];
    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FORMIDABLE ) as $key ) {
      $inputs[] = $this->render_transceiver_input_markup(
        $key,
        'item_meta[' . $key . ']',
        'presswell-formidable-' . sanitize_html_class( $key )
      );
    }

    if ( empty( $inputs ) ) {
      return;
    }

    echo $this->wrap_transceiver_inputs_markup( 'formidable', implode( '', $inputs ) );
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

    $pairs = [];
    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FORMIDABLE ) as $key ) {
      if ( ! isset( $values['item_meta'][ $key ] ) || is_array( $values['item_meta'][ $key ] ) ) {
        continue;
      }

      $clean = $this->service->sanitize_tracking_value( $key, $values['item_meta'][ $key ] );
      $values['item_meta'][ $key ] = $clean;

      if ( '' !== $clean ) {
        $pairs[ $key ] = $clean;
      }
    }

    $this->pending_formidable_tracking = $pairs;

    return $values;
  }

  /**
   * Persist tracking pairs after Formidable creates an entry.
   *
    * @param int   $entry_id Created entry id.
    * @param int   $form_id  Formidable form id.
    * @param array $args     Additional context.
   */
  public function persist_formidable_tracking_values( $entry_id, $form_id, $args ) {
    $entry_id = absint( $entry_id );
    if ( ! $entry_id ) {
      return;
    }

    $pairs = $this->pending_formidable_tracking;
    if ( empty( $pairs ) ) {
      return;
    }

    global $wpdb;
    $table    = $wpdb->prefix . 'frm_item_metas';
    $meta_key = 'presswell_tracking_signal_relay_tracking';

    $existing_id = 0;
    $rows        = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, meta_value FROM {$table} WHERE item_id = %d AND field_id = 0",
        $entry_id
      )
    );

    if ( is_array( $rows ) ) {
      foreach ( $rows as $row ) {
        if ( ! is_object( $row ) || ! isset( $row->meta_value ) ) {
          continue;
        }

        $decoded = maybe_unserialize( $row->meta_value );
        if ( ! is_array( $decoded ) || empty( $decoded['meta_key'] ) ) {
          continue;
        }

        if ( 'presswell_tracking_signal_relay_tracking' === $decoded['meta_key'] ) {
          $existing_id = isset( $row->id ) ? absint( $row->id ) : 0;
          break;
        }
      }
    }

    $stored = [
      'meta_key' => $meta_key,
      'pairs'    => $pairs,
    ];

    if ( $existing_id ) {
      $wpdb->update(
        $table,
        [ 'meta_value' => maybe_serialize( $stored ) ],
        [ 'id' => $existing_id ],
        [ '%s' ],
        [ '%d' ]
      );
    } else {
      $wpdb->insert(
        $table,
        [
          'item_id'    => $entry_id,
          'field_id'   => 0,
          'meta_value' => maybe_serialize( $stored ),
          'created_at' => current_time( 'mysql', 1 ),
        ],
        [ '%d', '%d', '%s', '%s' ]
      );
    }

    $this->pending_formidable_tracking = [];
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

    $pairs = $this->get_formidable_tracking_pairs( (int) $entry->id );
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

    $pairs = $this->get_formidable_tracking_pairs( (int) $entry_object->id );

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
  private function get_formidable_tracking_pairs( $entry_id ) {
    global $wpdb;

    $table = $wpdb->prefix . 'frm_item_metas';
    $rows  = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT meta_value FROM {$table} WHERE item_id = %d AND field_id = 0",
        $entry_id
      )
    );

    if ( empty( $rows ) || ! is_array( $rows ) ) {
      return [];
    }

    foreach ( $rows as $raw ) {
      $decoded = maybe_unserialize( $raw );
      if ( ! is_array( $decoded ) ) {
        continue;
      }

      if ( empty( $decoded['meta_key'] ) || 'presswell_tracking_signal_relay_tracking' !== $decoded['meta_key'] ) {
        continue;
      }

      if ( empty( $decoded['pairs'] ) || ! is_array( $decoded['pairs'] ) ) {
        return [];
      }

      return $decoded['pairs'];
    }

    return [];
  }
}
