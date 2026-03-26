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
   * Initialize Fluent Forms hooks when available.
   */
  public function maybe_bootstrap_fluent_forms() {
    if ( ! defined( 'FLUENTFORM_VERSION' ) && ! defined( 'FLUENTFORM' ) ) {
      return;
    }

    add_action( 'fluentform/before_form_render', [ $this, 'maybe_enqueue_fluent_forms_assets' ], 20, 2 );
    add_action( 'fluentform_before_form_render', [ $this, 'maybe_enqueue_fluent_forms_assets' ], 20, 2 );
    add_filter( 'fluentform/rendering_form', [ $this, 'inject_fluent_forms_tracking_inputs' ], 20, 1 );
    add_action( 'fluentform/submission_inserted', [ $this, 'persist_fluent_forms_tracking_data' ], 20, 3 );
    add_action( 'fluentform_submission_inserted', [ $this, 'persist_fluent_forms_tracking_data' ], 20, 3 );
  }

  /**
   * Enqueue front-end tracking script when a Fluent Form is rendered.
   */
  public function maybe_enqueue_fluent_forms_assets( $form = null, $atts = [] ) {
    $this->enqueue_tracking_script( PWTSR::ADAPTER_FLUENT_FORMS );
  }

  /**
   * Inject hidden tracking inputs into Fluent Forms rendered HTML.
   *
   * @param string $html Form markup.
   *
   * @return string
   */
  public function inject_fluent_forms_tracking_inputs( $html ) {
    if ( ! is_string( $html ) || false === stripos( $html, '</form>' ) ) {
      return $html;
    }

    if ( false !== strpos( $html, 'data-presswell-transceiver-adapter="fluent"' ) ) {
      return $html;
    }

    $inputs = [];
    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FLUENT_FORMS ) as $key ) {
      $inputs[] = $this->render_transceiver_input_markup(
        $key,
        $key,
        'presswell-fluent-' . sanitize_html_class( $key )
      );
    }

    if ( empty( $inputs ) ) {
      return $html;
    }

    $wrapper = $this->wrap_transceiver_inputs_markup( 'fluent', implode( '', $inputs ) );

    return preg_replace( '/<\/form>/i', $wrapper . '</form>', $html, 1 );
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

    $submissions_table = $wpdb->prefix . 'fluentform_submissions';
    $current_response  = $wpdb->get_var(
      $wpdb->prepare( "SELECT response FROM {$submissions_table} WHERE id = %d", $submission_id )
    );

    $response = [];
    if ( is_string( $current_response ) && '' !== $current_response ) {
      $decoded = json_decode( $current_response, true );
      if ( is_array( $decoded ) ) {
        $response = $decoded;
      }
    }

    foreach ( $pairs as $key => $value ) {
      $response[ $key ] = $value;
    }

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

    $details_table = $wpdb->prefix . 'fluentform_entry_details';

    foreach ( $pairs as $key => $value ) {
      $existing = (int) $wpdb->get_var(
        $wpdb->prepare(
          "SELECT COUNT(1) FROM {$details_table} WHERE submission_id = %d AND form_id = %d AND field_name = %s AND source_type = %s",
          $submission_id,
          $form_id,
          $key,
          'submission_item'
        )
      );

      if ( $existing > 0 ) {
        continue;
      }

      $wpdb->insert(
        $details_table,
        [
          'form_id'        => $form_id,
          'submission_id'  => $submission_id,
          'field_name'     => $key,
          'sub_field_name' => '',
          'field_value'    => $value,
          'source_type'    => 'submission_item',
        ],
        [ '%d', '%d', '%s', '%s', '%s', '%s' ]
      );
    }
  }

  /**
   * Read tracking values from the current request.
   *
   * @return array
   */
  private function get_fluent_forms_posted_tracking_values() {
    if ( empty( $_POST ) || ! is_array( $_POST ) ) {
      return [];
    }

    $values = [];
    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_FLUENT_FORMS ) as $key ) {
      if ( ! isset( $_POST[ $key ] ) || is_array( $_POST[ $key ] ) ) {
        continue;
      }

      $values[ $key ] = wp_unslash( $_POST[ $key ] );
    }

    return $values;
  }
}
