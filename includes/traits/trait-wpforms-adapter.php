<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * WPForms integration hooks.
 */
trait PWTSR_WPForms_Trait {

  /**
   * Guard against registering the custom field type twice.
   *
   * @var bool
   */
  private $wpforms_field_registered = false;

  /**
   * Initialize WPForms hooks when available.
   */
  public function maybe_bootstrap_wpforms() {
    if ( ! function_exists( 'wpforms' ) && ! defined( 'WPFORMS_VERSION' ) ) {
      return;
    }

    add_action( 'wpforms_loaded', [ $this, 'register_wpforms_transceiver_field' ], 20 );

    add_action( 'wpforms_frontend_output_before', [ $this, 'maybe_enqueue_wpforms_assets' ], 20, 2 );
    add_filter( 'wpforms_process_filter', [ $this, 'sanitize_wpforms_tracking_submission_values' ], 20, 3 );
    add_action( 'wpforms_process_entry_saved', [ $this, 'persist_wpforms_tracking_entry_meta' ], 20, 4 );

    add_filter( 'wpforms_smart_tags', [ $this, 'register_wpforms_tracking_smart_tags' ], 20, 1 );
    add_filter( 'wpforms_smarttags_process_value', [ $this, 'replace_wpforms_tracking_smart_tags' ], 20, 7 );

    // WPForms might already be fully loaded when this adapter initializes.
    $this->register_wpforms_transceiver_field();
  }

  /**
   * Register the custom transceiver field class with WPForms.
   */
  public function register_wpforms_transceiver_field() {
    if ( $this->wpforms_field_registered ) {
      return;
    }

    if ( ! class_exists( 'WPForms_Field' ) ) {
      return;
    }

    require_once dirname( __DIR__ ) . '/adapters/class-wpforms-field.php';

    if ( ! class_exists( 'PWTSR_WPForms_Field' ) ) {
      return;
    }

    new PWTSR_WPForms_Field();
    $this->wpforms_field_registered = true;
  }

  /**
   * Enqueue runtime assets only when a form includes the transceiver field.
   *
   * @param array $form_data Prepared form data.
   * @param mixed $form      Original form object.
   */
  public function maybe_enqueue_wpforms_assets( $form_data, $form ) {
    if ( ! $this->get_wpforms_transceiver_field_ids( $form_data ) ) {
      return;
    }

    $this->enqueue_tracking_script( PWTSR::ADAPTER_WPFORMS );
  }

  /**
   * Sanitize tracking values from transceiver compound fields.
   *
   * @param array $fields    Processed fields.
   * @param array $entry     Raw submission.
   * @param array $form_data Form data.
   *
   * @return array
   */
  public function sanitize_wpforms_tracking_submission_values( $fields, $entry, $form_data ) {
    if ( ! is_array( $fields ) || ! is_array( $form_data ) ) {
      return $fields;
    }

    $field_ids = $this->get_wpforms_transceiver_field_ids( $form_data );
    if ( empty( $field_ids ) ) {
      return $fields;
    }

    $keys = $this->service->get_tracking_keys( PWTSR::ADAPTER_WPFORMS );

    foreach ( $field_ids as $field_id ) {
      if ( ! isset( $fields[ $field_id ] ) || ! is_array( $fields[ $field_id ] ) ) {
        continue;
      }

      $row   = $fields[ $field_id ];
      $raw   = isset( $row['tracking'] ) && is_array( $row['tracking'] ) ? $row['tracking'] : [];
      $value = isset( $row['value'] ) ? $row['value'] : [];

      if ( is_array( $value ) ) {
        $raw = array_merge( $raw, $value );
      }

      $clean_pairs = [];
      foreach ( $keys as $key ) {
        $candidate = '';

        if ( isset( $raw[ $key ] ) && ! is_array( $raw[ $key ] ) ) {
          $candidate = $raw[ $key ];
        } elseif ( isset( $row[ $key ] ) && ! is_array( $row[ $key ] ) ) {
          $candidate = $row[ $key ];
        }

        $clean = $this->service->sanitize_tracking_value( $key, $candidate );
        if ( '' !== $clean ) {
          $clean_pairs[ $key ] = $clean;
        }
      }

      $lines = [];
      foreach ( $clean_pairs as $key => $pair_value ) {
        $lines[] = $key . ': ' . $pair_value;
      }

      $fields[ $field_id ]['tracking'] = $clean_pairs;
      $fields[ $field_id ]['value']    = implode( "\n", $lines );

      foreach ( $keys as $key ) {
        $fields[ $field_id ][ $key ] = isset( $clean_pairs[ $key ] ) ? $clean_pairs[ $key ] : '';
      }
    }

    return $fields;
  }

  /**
   * Persist tracking values into entry meta for robust smart-tag resolution.
   *
   * @param array      $fields    Processed fields.
   * @param array      $entry     Raw entry data.
   * @param array      $form_data Form data.
   * @param string|int $entry_id  Entry id.
   */
  public function persist_wpforms_tracking_entry_meta( $fields, $entry, $form_data, $entry_id ) {
    $entry_id = absint( $entry_id );
    if ( ! $entry_id ) {
      return;
    }

    $pairs = $this->get_wpforms_tracking_pairs( $fields, $form_data, $entry_id );
    if ( empty( $pairs ) ) {
      return;
    }

    $entry_meta = wpforms()->obj( 'entry_meta' );
    if ( ! $entry_meta ) {
      return;
    }

    $form_id = ! empty( $form_data['id'] ) ? absint( $form_data['id'] ) : 0;

    foreach ( $pairs as $key => $value ) {
      $entry_meta->add(
        [
          'entry_id' => $entry_id,
          'form_id'  => $form_id,
          'user_id'  => get_current_user_id(),
          'type'     => 'pwtsr_tracking_' . $key,
          'data'     => $value,
        ],
        'entry_meta'
      );
    }

    $entry_meta->add(
      [
        'entry_id' => $entry_id,
        'form_id'  => $form_id,
        'user_id'  => get_current_user_id(),
        'type'     => 'pwtsr_tracking_all',
        'data'     => wp_json_encode( $pairs ),
      ],
      'entry_meta'
    );
  }

  /**
   * Register Tracking smart tags for WPForms builder dropdowns.
   *
   * @param array $smart_tags Registered smart tags.
   *
   * @return array
   */
  public function register_wpforms_tracking_smart_tags( $smart_tags ) {
    if ( ! is_array( $smart_tags ) ) {
      $smart_tags = [];
    }

    $smart_tags['tracking_all'] = __( 'Tracking: All Values', 'presswell-signal-relay' );

    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_WPFORMS ) as $key ) {
      $smart_tags[ 'tracking_' . $key ] = sprintf(
        /* translators: %s tracking key name. */
        __( 'Tracking: %s', 'presswell-signal-relay' ),
        $key
      );
    }

    return $smart_tags;
  }

  /**
   * Replace custom tracking smart tags.
   *
   * @param mixed       $value            Existing smart tag value.
   * @param string      $tag_name         Smart tag name.
   * @param array       $form_data        Form data.
   * @param array       $fields           Processed fields.
   * @param int|string  $entry_id         Entry id.
   * @param object|null $smart_tag_object Smart tag object.
   * @param string      $context          Context string.
   *
   * @return mixed
   */
  public function replace_wpforms_tracking_smart_tags( $value, $tag_name, $form_data, $fields, $entry_id, $smart_tag_object, $context ) {
    if ( ! is_string( $tag_name ) || 0 !== strpos( $tag_name, 'tracking_' ) ) {
      return $value;
    }

    $pairs = $this->get_wpforms_tracking_pairs( $fields, $form_data, $entry_id );

    if ( 'tracking_all' === $tag_name ) {
      if ( empty( $pairs ) ) {
        return '';
      }

      $lines = [];
      foreach ( $pairs as $key => $pair_value ) {
        $lines[] = $key . ': ' . $pair_value;
      }

      return implode( "\n", $lines );
    }

    $key = substr( $tag_name, strlen( 'tracking_' ) );
    if ( '' === $key ) {
      return '';
    }

    return isset( $pairs[ $key ] ) ? $pairs[ $key ] : '';
  }

  /**
   * Return ids for transceiver fields in a WPForms form config.
   *
   * @param array $form_data Form configuration.
   *
   * @return int[]
   */
  private function get_wpforms_transceiver_field_ids( $form_data ) {
    if ( ! is_array( $form_data ) || empty( $form_data['fields'] ) || ! is_array( $form_data['fields'] ) ) {
      return [];
    }

    $ids = [];
    foreach ( $form_data['fields'] as $field_id => $field ) {
      if ( ! is_array( $field ) ) {
        continue;
      }

      if ( empty( $field['type'] ) || PWTSR::FIELD_TYPE !== $field['type'] ) {
        continue;
      }

      $id = is_numeric( $field_id ) ? absint( $field_id ) : ( ! empty( $field['id'] ) ? absint( $field['id'] ) : 0 );
      if ( $id ) {
        $ids[] = $id;
      }
    }

    return array_values( array_unique( $ids ) );
  }

  /**
   * Get tracking pairs from current fields with entry-meta fallback.
   *
   * @param array      $fields    Processed fields.
   * @param array      $form_data Form data.
   * @param int|string $entry_id  Entry id.
   *
   * @return array
   */
  private function get_wpforms_tracking_pairs( $fields, $form_data, $entry_id ) {
    $pairs = [];
    $keys  = $this->service->get_tracking_keys( PWTSR::ADAPTER_WPFORMS );

    foreach ( $this->get_wpforms_transceiver_field_ids( $form_data ) as $field_id ) {
      if ( ! is_array( $fields ) || ! isset( $fields[ $field_id ] ) || ! is_array( $fields[ $field_id ] ) ) {
        continue;
      }

      $row = $fields[ $field_id ];

      foreach ( $keys as $key ) {
        $candidate = '';

        if ( isset( $row['tracking'][ $key ] ) && ! is_array( $row['tracking'][ $key ] ) ) {
          $candidate = $row['tracking'][ $key ];
        } elseif ( isset( $row[ $key ] ) && ! is_array( $row[ $key ] ) ) {
          $candidate = $row[ $key ];
        }

        $clean = $this->service->sanitize_tracking_value( $key, $candidate );
        if ( '' !== $clean ) {
          $pairs[ $key ] = $clean;
        }
      }
    }

    if ( ! empty( $pairs ) ) {
      return $pairs;
    }

    $entry_id = absint( $entry_id );
    if ( ! $entry_id ) {
      return [];
    }

    $entry_meta = wpforms()->obj( 'entry_meta' );
    if ( ! $entry_meta ) {
      return [];
    }

    $all_meta = $entry_meta->get_meta(
      [
        'entry_id' => $entry_id,
        'type'     => 'pwtsr_tracking_all',
        'number'   => 1,
      ]
    );

    if ( ! empty( $all_meta[0]->data ) && is_string( $all_meta[0]->data ) ) {
      $decoded = json_decode( $all_meta[0]->data, true );
      if ( is_array( $decoded ) ) {
        foreach ( $decoded as $k => $v ) {
          $clean_key = sanitize_key( $k );
          if ( '' === $clean_key || is_array( $v ) ) {
            continue;
          }

          $clean_val = $this->service->sanitize_tracking_value( $clean_key, (string) $v );
          if ( '' !== $clean_val ) {
            $pairs[ $clean_key ] = $clean_val;
          }
        }
      }
    }

    if ( ! empty( $pairs ) ) {
      return $pairs;
    }

    foreach ( $keys as $key ) {
      $rows = $entry_meta->get_meta(
        [
          'entry_id' => $entry_id,
          'type'     => 'pwtsr_tracking_' . $key,
          'number'   => 1,
        ]
      );

      if ( empty( $rows[0]->data ) || ! is_string( $rows[0]->data ) ) {
        continue;
      }

      $clean = $this->service->sanitize_tracking_value( $key, $rows[0]->data );
      if ( '' !== $clean ) {
        $pairs[ $key ] = $clean;
      }
    }

    return $pairs;
  }
}
