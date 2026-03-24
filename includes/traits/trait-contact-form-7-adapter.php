<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Contact Form 7 integration hooks.
 */
trait PWSL_Contact_Form_7_Trait {

  /**
   * Initialize Contact Form 7 hooks when available.
   */
  public function maybe_bootstrap_contact_form_7() {
    if ( ! defined( 'WPCF7_VERSION' ) && ! function_exists( 'wpcf7' ) ) {
      return;
    }

    add_action( 'wpcf7_enqueue_scripts', [ $this, 'maybe_enqueue_contact_form_7_assets' ] );
    add_filter( 'wpcf7_form_elements', [ $this, 'inject_contact_form_7_tracking_inputs' ], 20, 1 );
    add_filter( 'wpcf7_posted_data', [ $this, 'sanitize_contact_form_7_posted_data' ], 20, 1 );
    add_filter( 'wpcf7_collect_mail_tags', [ $this, 'add_contact_form_7_mail_tag_suggestions' ], 20, 3 );
    add_filter( 'wpcf7_special_mail_tags', [ $this, 'render_contact_form_7_special_mail_tags' ], 20, 4 );
    add_filter( 'wpcf7_mail_components', [ $this, 'append_contact_form_7_tracking_to_mail' ], 20, 3 );
  }

  /**
   * Enqueue front-end tracking script for Contact Form 7 forms.
   */
  public function maybe_enqueue_contact_form_7_assets() {
    $this->enqueue_tracking_script( PWSL::ADAPTER_CONTACT_FORM_7 );
  }

  /**
   * Inject hidden tracking inputs into Contact Form 7 form markup.
   *
   * @param string $html Contact Form 7 form elements HTML.
   *
   * @return string
   */
  public function inject_contact_form_7_tracking_inputs( $html ) {
    if ( false !== strpos( $html, 'data-presswell-transceiver-cf7="1"' ) ) {
      return $html;
    }

    $inputs = [];
    foreach ( $this->service->get_tracking_keys( PWSL::ADAPTER_CONTACT_FORM_7 ) as $key ) {
      $inputs[] = sprintf(
        '<input type="hidden" name="%1$s" value="" data-presswell-transceiver="%1$s" />',
        esc_attr( $key )
      );
    }

    if ( empty( $inputs ) ) {
      return $html;
    }

    $wrapper = sprintf(
      '<div class="presswell-cf7-transceiver" data-presswell-transceiver-cf7="1" style="display:none" aria-hidden="true">%s</div>',
      implode( '', $inputs )
    );

    return $html . $wrapper;
  }

  /**
   * Sanitize tracking values in Contact Form 7 posted data.
   *
   * @param array $posted_data Contact Form 7 posted data.
   *
   * @return array
   */
  public function sanitize_contact_form_7_posted_data( $posted_data ) {
    if ( ! is_array( $posted_data ) ) {
      $posted_data = [];
    }

    if ( empty( $_POST ) || ! is_array( $_POST ) ) {
      return $posted_data;
    }

    foreach ( $this->service->get_tracking_keys( PWSL::ADAPTER_CONTACT_FORM_7 ) as $key ) {
      $raw = null;

      if ( isset( $posted_data[ $key ] ) && ! is_array( $posted_data[ $key ] ) ) {
        $raw = $posted_data[ $key ];
      } elseif ( isset( $_POST[ $key ] ) && ! is_array( $_POST[ $key ] ) ) {
        $raw = wp_unslash( $_POST[ $key ] );
      }

      if ( null === $raw ) {
        continue;
      }

      $posted_data[ $key ] = $this->service->sanitize_tracking_value( $key, $raw );
    }

    return $posted_data;
  }

  /**
   * Add Signal Relay special mail tags to Contact Form 7 Mail panel suggestions.
   *
   * @param array $mailtags Existing suggested mail tags.
   *
   * @return array
   */
  public function add_contact_form_7_mail_tag_suggestions( $mailtags ) {
    if ( ! is_array( $mailtags ) ) {
      $mailtags = [];
    }

    $mailtags[] = 'tracking-values';
    // $mailtags[] = 'pwsr_transceiver';

    return array_values( array_unique( array_filter( $mailtags ) ) );
  }

  /**
   * Render custom Contact Form 7 special mail tags for transceiver data.
   *
   * Supported tags:
   * - [tracking-values]
   * - [pwsr_transceiver]
   *
   * @param string $output Existing output.
   * @param string $name   Special mail tag name.
   * @param bool   $html   Whether HTML output is requested.
  * @param mixed  $mail_tag Mail tag object when available.
   *
   * @return string
   */
  public function render_contact_form_7_special_mail_tags( $output, $name, $html, $mail_tag = null ) {
    if ( ! in_array( (string) $name, [ 'tracking-values', 'pwsr_transceiver'], true ) ) {
      return $output;
    }

    $pairs = $this->get_contact_form_7_tracking_pairs();
    if ( empty( $pairs ) ) {
      return '';
    }

    return $this->format_contact_form_7_tracking_pairs( $pairs, (bool) $html );
  }

  /**
   * Optionally append transceiver data to outgoing Contact Form 7 emails.
   *
   * @param array $components Mail components.
   * @param mixed $contact_form Contact Form 7 form object.
   * @param array $mail Mail configuration.
   *
   * @return array
   */
  public function append_contact_form_7_tracking_to_mail( $components, $contact_form, $mail ) {
    $enabled = (bool) apply_filters(
      'presswell_signal_relay_cf7_auto_append_tracking',
      false,
      $contact_form,
      $mail
    );

    if ( ! $enabled || ! is_array( $components ) || empty( $components['body'] ) ) {
      return $components;
    }

    $body = (string) $components['body'];
    if ( false !== strpos( $body, '[tracking-values]' ) || false !== strpos( $body, '[pwsr_transceiver]' ) ) {
      return $components;
    }

    $pairs = $this->get_contact_form_7_tracking_pairs();
    if ( empty( $pairs ) ) {
      return $components;
    }

    $is_html = ! empty( $mail['use_html'] );
    $label   = (string) apply_filters( 'presswell_signal_relay_cf7_tracking_label', 'Tracking' );
    $payload = $this->format_contact_form_7_tracking_pairs( $pairs, $is_html );

    if ( $is_html ) {
      $section = sprintf( '<p><strong>%s:</strong><br />%s</p>', esc_html( $label ), $payload );
      $components['body'] = $body . "\n\n" . $section;
      return $components;
    }

    $section = sprintf( "%s:\n%s", $label, $payload );
    $components['body'] = $body . "\n\n" . $section;

    return $components;
  }

  /**
   * Resolve sanitized transceiver pairs from current Contact Form 7 submission.
   *
   * @return array
   */
  private function get_contact_form_7_tracking_pairs() {
    if ( ! class_exists( 'WPCF7_Submission' ) ) {
      return [];
    }

    $submission = WPCF7_Submission::get_instance();
    if ( ! $submission || ! method_exists( $submission, 'get_posted_data' ) ) {
      return [];
    }

    $posted_data = $submission->get_posted_data();
    if ( ! is_array( $posted_data ) ) {
      return [];
    }

    $pairs = [];
    foreach ( $this->service->get_tracking_keys( PWSL::ADAPTER_CONTACT_FORM_7 ) as $key ) {
      if ( ! isset( $posted_data[ $key ] ) || is_array( $posted_data[ $key ] ) ) {
        continue;
      }

      $clean = $this->service->sanitize_tracking_value( $key, $posted_data[ $key ] );
      if ( '' === $clean ) {
        continue;
      }

      $pairs[ $key ] = $clean;
    }

    return $pairs;
  }

  /**
   * Format transceiver key/value pairs for mail content.
   *
   * @param array $pairs   Key/value pairs.
   * @param bool  $as_html Whether to output HTML line breaks.
   *
   * @return string
   */
  private function format_contact_form_7_tracking_pairs( $pairs, $as_html = false ) {
    $lines = [];
    foreach ( $pairs as $key => $value ) {
      if ( $as_html ) {
        $lines[] = sprintf( '%s: %s', esc_html( $key ), esc_html( $value ) );
        continue;
      }

      $lines[] = sprintf( '%s: %s', $key, $value );
    }

    if ( $as_html ) {
      return implode( '<br />', $lines );
    }

    return implode( "\n", $lines );
  }
}
