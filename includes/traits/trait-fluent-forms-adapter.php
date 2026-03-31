<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Fluent Forms integration hooks.
 */
trait PWTSR_Fluent_Forms_Trait {

  /**
   * Guard against duplicate handling when both deprecated and current hooks fire.
   *
   * @var array
   */
  private $processed_fluent_submissions = [];

  /**
   * Guard against duplicate enqueue calls when deprecated and current hooks fire.
   *
   * @var array
   */
  private $enqueued_fluent_forms = [];

  /**
   * Guard against duplicate Fluent field manager registration.
   *
   * @var bool
   */
  private $fluent_field_registered = false;

  /**
   * Cache whether fluentform_entry_details has a source_type column.
   *
   * @var bool|null
   */
  private $fluent_entry_details_has_source_type = null;

  /**
   * Initialize Fluent Forms hooks when available.
   */
  public function maybe_bootstrap_fluent_forms() {
    if ( ! defined( 'FLUENTFORM_VERSION' ) && ! defined( 'FLUENTFORM' ) ) {
      return;
    }

    $this->register_fluent_forms_transceiver_field();

    add_action( 'fluentform/before_form_render', [ $this, 'maybe_enqueue_fluent_forms_assets' ], 20, 2 );
    add_action( 'fluentform_before_form_render', [ $this, 'maybe_enqueue_fluent_forms_assets' ], 20, 2 );

    add_action( 'fluentform/submission_inserted', [ $this, 'persist_fluent_forms_tracking_data' ], 20, 3 );
    add_action( 'fluentform_submission_inserted', [ $this, 'persist_fluent_forms_tracking_data' ], 20, 3 );

    add_filter( 'fluentform/find_submission', [ $this, 'normalize_fluent_forms_submission_tracking_payload' ], 20, 2 );
    add_filter( 'fluentform/form_settings_smartcodes', [ $this, 'register_fluent_forms_tracking_smartcodes' ], 20, 2 );
    add_filter( 'fluentform/smartcode_group_tracking', [ $this, 'resolve_fluent_forms_tracking_smartcode' ], 20, 2 );
    add_filter( 'fluentform/email_body', [ $this, 'format_fluent_forms_tracking_all_email_body' ], 20, 4 );
    add_filter( 'fluentform/email_subject', [ $this, 'format_fluent_forms_tracking_all_email_subject' ], 20, 4 );
  }

  /**
   * Register Presswell transceiver field type for Fluent Forms editor.
   */
  public function register_fluent_forms_transceiver_field() {
    if ( $this->fluent_field_registered ) {
      return;
    }

    if ( ! class_exists( 'FluentForm\\App\\Services\\FormBuilder\\BaseFieldManager' ) || ! class_exists( 'PWTSR_Fluent_Forms_Field' ) ) {
      return;
    }

    new PWTSR_Fluent_Forms_Field();
    $this->fluent_field_registered = true;
  }

  /**
   * Enqueue tracking script only for forms that include the transceiver field.
   *
   * @param mixed $form Fluent form model.
   * @param array $atts Render attributes.
   */
  public function maybe_enqueue_fluent_forms_assets( $form = null, $atts = [] ) {
    if ( ! $this->fluent_form_has_transceiver_field( $form ) ) {
      return;
    }

    $form_id = 0;
    if ( is_object( $form ) && isset( $form->id ) ) {
      $form_id = absint( $form->id );
    }

    $dedupe_key = $form_id ? 'form_' . $form_id : 'unknown';
    if ( isset( $this->enqueued_fluent_forms[ $dedupe_key ] ) ) {
      return;
    }

    $this->enqueued_fluent_forms[ $dedupe_key ] = true;

    $this->enqueue_tracking_script( PWTSR::ADAPTER_FLUENT_FORMS );
  }

  /**
   * Persist sanitized tracking pairs for a submitted Fluent Forms entry.
   *
   * @param int   $submission_id Submission id.
   * @param array $form_data     Submitted form data.
   * @param mixed $form          Form model.
   */
  public function persist_fluent_forms_tracking_data( $submission_id, $form_data, $form ) {
    $submission_id = absint( $submission_id );
    if ( ! $submission_id ) {
      return;
    }

    if ( isset( $this->processed_fluent_submissions[ $submission_id ] ) ) {
      return;
    }

    if ( ! $this->fluent_form_has_transceiver_field( $form ) ) {
      return;
    }

    $this->processed_fluent_submissions[ $submission_id ] = true;

    $form_id = 0;
    if ( is_object( $form ) && isset( $form->id ) ) {
      $form_id = absint( $form->id );
    } elseif ( is_array( $form ) && isset( $form['id'] ) ) {
      $form_id = absint( $form['id'] );
    }

    $posted = $this->get_fluent_forms_posted_tracking_values();
    if ( empty( $posted ) ) {
      return;
    }

    $pairs = [];
    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FLUENT_FORMS ) as $key ) {
      if ( ! isset( $posted[ $key ] ) ) {
        continue;
      }

      $clean = $this->service->sanitize_tracking_value( $key, $posted[ $key ] );
      if ( '' === $clean ) {
        continue;
      }

      $pairs[ $key ] = $clean;
    }

    if ( empty( $pairs ) ) {
      return;
    }

    $this->update_fluent_forms_submission_response( $submission_id, $pairs );
    $this->insert_fluent_forms_entry_details( $submission_id, $form_id, $pairs );
  }

  /**
   * Merge tracking pairs into Fluent Forms submission response JSON.
   *
   * @param int   $submission_id Submission ID.
   * @param array $pairs         Tracking pairs.
   */
  private function update_fluent_forms_submission_response( $submission_id, $pairs ) {
    global $wpdb;

    $submissions_table = esc_sql( $wpdb->prefix . 'fluentform_submissions' );
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is internal and escaped.
    $current_response  = $wpdb->get_var(
      $wpdb->prepare( "SELECT response FROM {$submissions_table} WHERE id = %d", $submission_id )
    );
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

    $response = [];
    if ( is_string( $current_response ) && '' !== $current_response ) {
      $decoded = json_decode( $current_response, true );
      if ( is_array( $decoded ) ) {
        $response = $decoded;
      }
    }

    $existing_group = [];
    if ( isset( $response['pwtsr_tracking'] ) && is_array( $response['pwtsr_tracking'] ) ) {
      $existing_group = $response['pwtsr_tracking'];
    }

    $response['pwtsr_tracking'] = array_merge( $existing_group, $pairs );

    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FLUENT_FORMS ) as $key ) {
      if ( isset( $response[ $key ] ) ) {
        unset( $response[ $key ] );
      }
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fluent Forms submission record must be updated directly.
    $wpdb->update(
      $submissions_table,
      [ 'response' => wp_json_encode( $response ) ],
      [ 'id' => $submission_id ],
      [ '%s' ],
      [ '%d' ]
    );
  }

  /**
   * Persist tracking pairs into Fluent Forms entry details table.
   *
   * @param int   $submission_id Submission ID.
   * @param int   $form_id       Form ID.
   * @param array $pairs         Tracking pairs.
   */
  private function insert_fluent_forms_entry_details( $submission_id, $form_id, $pairs ) {
    global $wpdb;

    if ( ! $form_id ) {
      return;
    }

    $details_table = esc_sql( $wpdb->prefix . 'fluentform_entry_details' );
    $has_source_type = $this->fluent_forms_entry_details_has_source_type( $details_table );

    foreach ( $pairs as $key => $value ) {
      if ( $has_source_type ) {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is internal and escaped.
        $existing = (int) $wpdb->get_var(
          $wpdb->prepare(
            "SELECT COUNT(1) FROM {$details_table} WHERE submission_id = %d AND form_id = %d AND field_name = %s AND source_type = %s",
            $submission_id,
            $form_id,
            $key,
            'submission_item'
          )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
      } else {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is internal and escaped.
        $existing = (int) $wpdb->get_var(
          $wpdb->prepare(
            "SELECT COUNT(1) FROM {$details_table} WHERE submission_id = %d AND form_id = %d AND field_name = %s",
            $submission_id,
            $form_id,
            $key
          )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
      }

      if ( $existing > 0 ) {
        continue;
      }

      $insert_data = [
        'form_id'        => $form_id,
        'submission_id'  => $submission_id,
        'field_name'     => $key,
        'sub_field_name' => '',
        'field_value'    => $value,
      ];

      $insert_format = [ '%d', '%d', '%s', '%s', '%s' ];

      if ( $has_source_type ) {
        $insert_data['source_type'] = 'submission_item';
        $insert_format[]            = '%s';
      }

      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fluent Forms entry detail rows must be written directly.
      $wpdb->insert(
        $details_table,
        $insert_data,
        $insert_format
      );
    }
  }

  /**
   * Ensure tracking keys have labels in Fluent entry views.
   *
   * @param array    $labels Existing label map.
   * @param int|mixed $form_id Fluent Forms form ID.
   *
   * @return array
   */
  public function register_fluent_forms_tracking_entry_labels( $labels, $form_id = 0 ) {
    return is_array( $labels ) ? $labels : [];
  }

  /**
   * Register tracking smartcodes for notification/editor UI.
   *
   * @param array $groups Existing smartcode groups.
   * @param mixed $form   Fluent form object.
   *
   * @return array
   */
  public function register_fluent_forms_tracking_smartcodes( $groups, $form ) {
    if ( ! is_array( $groups ) ) {
      $groups = [];
    }

    $shortcodes = [];

    $shortcodes['{tracking.all}'] = __( 'All Tracking Signals', 'presswell-signal-relay' );

    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FLUENT_FORMS ) as $key ) {
      $shortcodes[ '{tracking.' . $key . '}' ] = $this->build_fluent_forms_tracking_label( $key );
    }

    if ( empty( $shortcodes ) ) {
      return $groups;
    }

    $groups[] = [
      'title'      => __( 'Tracking Signals', 'presswell-signal-relay' ),
      'shortcodes' => $shortcodes,
    ];

    return $groups;
  }

  /**
   * Resolve {tracking.*} smartcodes at send/render time.
   *
   * @param string $property Smartcode property, e.g. utm_source.
   * @param mixed  $parser   ShortCodeParser instance.
   *
   * @return string
   */
  public function resolve_fluent_forms_tracking_smartcode( $property, $parser ) {
    if ( ! is_string( $property ) || '' === $property ) {
      return $property;
    }

    $entry = null;
    if ( is_object( $parser ) && method_exists( $parser, 'getEntry' ) ) {
      $entry = $parser::getEntry();
    }

    if ( ! is_object( $entry ) ) {
      return '';
    }

    $response = $this->extract_fluent_forms_submission_response_array( $entry );
    if ( empty( $response ) ) {
      return '';
    }

    $pairs = $this->extract_fluent_forms_tracking_pairs( $response );

    if ( 'all' === $property ) {
      $pairs = [];
      foreach ( $this->extract_fluent_forms_tracking_pairs( $response ) as $key => $pair_value ) {
        if ( '' === (string) $pair_value ) {
          continue;
        }

        $pairs[] = $this->build_fluent_forms_tracking_label( $key ) . ': ' . (string) $pair_value;
      }

      $all_output = implode( "\n", $pairs );
      if ( $this->is_fluent_forms_notification_smartcode_context( $parser ) ) {
        return $this->wrap_fluent_forms_tracking_all_token( $all_output );
      }

      return $all_output;
    }

    if ( ! isset( $pairs[ $property ] ) ) {
      return '';
    }

    return (string) $pairs[ $property ];
  }

  /**
   * Convert tokenized tracking.all value to HTML/Plain text for outgoing email body.
   *
   * @param mixed $email_body   Parsed email body.
   * @param array $notification Notification settings.
   * @param array $submitted    Submitted form values.
   * @param mixed $form         Fluent form object.
   *
   * @return mixed
   */
  public function format_fluent_forms_tracking_all_email_body( $email_body, $notification, $submitted, $form ) {
    if ( ! is_string( $email_body ) || false === strpos( $email_body, '[[PWTSR_TRACKING_ALL]]' ) ) {
      return $email_body;
    }

    $is_plain = is_array( $notification ) && isset( $notification['asPlainText'] ) && 'yes' === (string) $notification['asPlainText'];

    return preg_replace_callback(
      '/\[\[PWTSR_TRACKING_ALL\]\](.*?)\[\[\/PWTSR_TRACKING_ALL\]\]/s',
      function ( $matches ) use ( $is_plain ) {
        $content = str_replace( [ "\r\n", "\r" ], "\n", (string) $matches[1] );

        if ( $is_plain ) {
          return $content;
        }

        return str_replace( "\n", '<br />', $content );
      },
      $email_body
    );
  }

  /**
   * Strip token markers/newlines from subject if tracking.all is used there.
   *
   * @param mixed $subject      Parsed subject.
   * @param array $notification Notification settings.
   * @param array $submitted    Submitted form values.
   * @param mixed $form         Fluent form object.
   *
   * @return mixed
   */
  public function format_fluent_forms_tracking_all_email_subject( $subject, $notification, $submitted, $form ) {
    if ( ! is_string( $subject ) || false === strpos( $subject, 'PWTSR_TRACKING_ALL' ) ) {
      return $subject;
    }

    $subject = preg_replace( '/\[\[\/?PWTSR_TRACKING_ALL\]\]/', '', $subject );
    $subject = preg_replace( '/\s*[\r\n]+\s*/', ' | ', $subject );

    return trim( $subject );
  }

  /**
   * Normalize submission payload so tracking values are grouped under pwtsr_tracking.
   *
   * @param object $submission Submission model.
   * @param int    $form_id    Form id.
   *
   * @return object
   */
  public function normalize_fluent_forms_submission_tracking_payload( $submission, $form_id = 0 ) {
    if ( ! is_object( $submission ) ) {
      return $submission;
    }

    $response = $this->extract_fluent_forms_submission_response_array( $submission );
    if ( empty( $response ) ) {
      return $submission;
    }

    $pairs = $this->extract_fluent_forms_tracking_pairs( $response );
    if ( empty( $pairs ) ) {
      return $submission;
    }

    $response['pwtsr_tracking'] = $pairs;
    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FLUENT_FORMS ) as $key ) {
      if ( isset( $response[ $key ] ) ) {
        unset( $response[ $key ] );
      }
    }

    $submission->response = wp_json_encode( $response );

    if ( isset( $submission->user_inputs ) && is_array( $submission->user_inputs ) ) {
      $lines = [];
      foreach ( $pairs as $key => $value ) {
        $lines[] = $this->build_fluent_forms_tracking_label( $key ) . ': ' . $value;
      }
      $submission->user_inputs['pwtsr_tracking'] = implode( "\n", $lines );
    }

    return $submission;
  }

  /**
   * Build a readable label from a tracking key.
   *
   * @param string $key Tracking key.
   *
   * @return string
   */
  private function build_fluent_forms_tracking_label( $key ) {
    return ucwords( str_replace( '_', ' ', (string) $key ) );
  }

  /**
   * Wrap tracking.all content with a token marker for downstream email formatting.
   *
   * @param string $content Tracking lines.
   *
   * @return string
   */
  private function wrap_fluent_forms_tracking_all_token( $content ) {
    return '[[PWTSR_TRACKING_ALL]]' . (string) $content . '[[/PWTSR_TRACKING_ALL]]';
  }

  /**
   * Determine whether current smartcode parse context is Fluent notifications.
   *
   * @param mixed $parser ShortCodeParser instance.
   *
   * @return bool
   */
  private function is_fluent_forms_notification_smartcode_context( $parser ) {
    if ( ! is_object( $parser ) || ! method_exists( $parser, 'getProvider' ) ) {
      return false;
    }

    return 'notifications' === (string) $parser::getProvider();
  }

  /**
   * Normalize Fluent submission response payload to array.
   *
   * @param object $submission Submission or entry object.
   *
   * @return array
   */
  private function extract_fluent_forms_submission_response_array( $submission ) {
    if ( ! is_object( $submission ) || ! isset( $submission->response ) ) {
      return [];
    }

    if ( is_array( $submission->response ) ) {
      return $submission->response;
    }

    if ( is_object( $submission->response ) ) {
      return (array) $submission->response;
    }

    if ( is_string( $submission->response ) && '' !== $submission->response ) {
      $decoded = json_decode( $submission->response, true );
      return is_array( $decoded ) ? $decoded : [];
    }

    return [];
  }

  /**
   * Check whether entry_details table has source_type.
   *
   * @param string $details_table Table name.
   *
   * @return bool
   */
  private function fluent_forms_entry_details_has_source_type( $details_table ) {
    if ( null !== $this->fluent_entry_details_has_source_type ) {
      return (bool) $this->fluent_entry_details_has_source_type;
    }

    global $wpdb;

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is internal and escaped.
    $column_name = $wpdb->get_var(
      $wpdb->prepare( "SHOW COLUMNS FROM {$details_table} LIKE %s", 'source_type' )
    );
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

    $this->fluent_entry_details_has_source_type = ! empty( $column_name );

    return (bool) $this->fluent_entry_details_has_source_type;
  }

  /**
   * Read tracking values from the current request.
   *
   * @return array
   */
  private function get_fluent_forms_posted_tracking_values() {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Fluent Forms validates nonce before this callback runs.
    $request_post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : [];
    if ( empty( $request_post ) ) {
      return [];
    }

    $values = [];
    if ( isset( $request_post['pwtsr_tracking'] ) && is_array( $request_post['pwtsr_tracking'] ) ) {
      foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FLUENT_FORMS ) as $key ) {
        if ( ! isset( $request_post['pwtsr_tracking'][ $key ] ) || is_array( $request_post['pwtsr_tracking'][ $key ] ) ) {
          continue;
        }

        $values[ $key ] = $request_post['pwtsr_tracking'][ $key ];
      }

      if ( ! empty( $values ) ) {
        return $values;
      }
    }

    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FLUENT_FORMS ) as $key ) {
      if ( ! isset( $request_post[ $key ] ) || is_array( $request_post[ $key ] ) ) {
        continue;
      }

      $values[ $key ] = $request_post[ $key ];
    }

    return $values;
  }

  /**
   * Extract tracking pairs from a Fluent response payload.
   *
   * @param array $response Submission response payload.
   *
   * @return array
   */
  private function extract_fluent_forms_tracking_pairs( $response ) {
    if ( ! is_array( $response ) ) {
      return [];
    }

    $pairs = [];
    $keys  = $this->service->get_tracking_keys( PWTSR::ADAPTER_FLUENT_FORMS );

    if ( isset( $response['pwtsr_tracking'] ) && is_array( $response['pwtsr_tracking'] ) ) {
      foreach ( $keys as $key ) {
        if ( ! isset( $response['pwtsr_tracking'][ $key ] ) || is_array( $response['pwtsr_tracking'][ $key ] ) ) {
          continue;
        }

        $clean = $this->service->sanitize_tracking_value( $key, $response['pwtsr_tracking'][ $key ] );
        if ( '' !== $clean ) {
          $pairs[ $key ] = $clean;
        }
      }
    }

    foreach ( $keys as $key ) {
      if ( isset( $pairs[ $key ] ) ) {
        continue;
      }

      if ( ! isset( $response[ $key ] ) || is_array( $response[ $key ] ) ) {
        continue;
      }

      $clean = $this->service->sanitize_tracking_value( $key, $response[ $key ] );
      if ( '' !== $clean ) {
        $pairs[ $key ] = $clean;
      }
    }

    return $pairs;
  }

  /**
   * Determine if a Fluent form contains the Presswell transceiver field.
   *
   * @param mixed $form Fluent form model.
   *
   * @return bool
   */
  private function fluent_form_has_transceiver_field( $form ) {
    if ( ! is_object( $form ) || empty( $form->fields ) || ! is_array( $form->fields ) || empty( $form->fields['fields'] ) || ! is_array( $form->fields['fields'] ) ) {
      return false;
    }

    foreach ( $form->fields['fields'] as $field ) {
      if ( ! is_array( $field ) || empty( $field['element'] ) ) {
        continue;
      }

      if ( PWTSR::FIELD_TYPE === $field['element'] ) {
        return true;
      }
    }

    return false;
  }
}
