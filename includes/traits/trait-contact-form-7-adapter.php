<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Contact Form 7 integration hooks.
 */
trait PWTSR_Contact_Form_7_Trait {

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
  }

  /**
   * Enqueue front-end tracking script for Contact Form 7 forms.
   */
  public function maybe_enqueue_contact_form_7_assets() {
    $this->enqueue_tracking_script( PWTSR::ADAPTER_CONTACT_FORM_7 );
  }

  /**
   * Inject hidden tracking inputs into Contact Form 7 form markup.
   *
   * @param string $html Contact Form 7 form elements HTML.
   *
   * @return string
   */
  public function inject_contact_form_7_tracking_inputs( $html ) {
    if ( false !== strpos( $html, 'data-presswell-transceiver-adapter="cf7"' ) ) {
      return $html;
    }

    $inputs = [];
    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_CONTACT_FORM_7 ) as $key ) {
      $inputs[] = $this->render_transceiver_input_markup(
        $key,
        $key,
        'presswell-cf7-' . sanitize_html_class( $key )
      );
    }

    if ( empty( $inputs ) ) {
      return $html;
    }

    $wrapper = $this->wrap_transceiver_inputs_markup( 'cf7', implode( '', $inputs ) );

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

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Contact Form 7 validates nonce before posted-data filters run.
    $request_post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : [];
    if ( empty( $request_post ) ) {
      return $posted_data;
    }

    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_CONTACT_FORM_7 ) as $key ) {
      $raw = null;

      if ( isset( $posted_data[ $key ] ) && ! is_array( $posted_data[ $key ] ) ) {
        $raw = $posted_data[ $key ];
      } elseif ( isset( $request_post[ $key ] ) && ! is_array( $request_post[ $key ] ) ) {
        $raw = $request_post[ $key ];
      }

      if ( null === $raw ) {
        continue;
      }

      $posted_data[ $key ] = $this->service->sanitize_tracking_value( $key, $raw );
    }

    return $posted_data;
  }

  /**
  * Add Tracking Signal Relay special mail tags to Contact Form 7 Mail panel suggestions.
   *
   * @param array $mailtags Existing suggested mail tags.
   *
   * @return array
   */
  public function add_contact_form_7_mail_tag_suggestions( $mailtags ) {
    if ( ! is_array( $mailtags ) ) {
      $mailtags = [];
    }

    $mailtags[] = 'tracking-all';
    // $mailtags[] = 'tracking-values';

    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_CONTACT_FORM_7 ) as $key ) {
      $mailtags[] = 'tracking-' . $key;
    }

    return array_values( array_unique( array_filter( $mailtags ) ) );
  }

  /**
   * Render custom Contact Form 7 special mail tags for transceiver data.
   *
   * Supported tags:
   * - [tracking-all]
   * - [tracking-values]
   * - [tracking-{key}]
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
    $pairs = $this->get_contact_form_7_tracking_pairs();
    if ( empty( $pairs ) ) {
      return $output;
    }

    $resolved = $this->resolve_contact_form_7_tracking_tag_value( (string) $name, $pairs, (bool) $html );
    if ( null === $resolved ) {
      return $output;
    }

    return $resolved;
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
    foreach ( $this->service->get_tracking_keys( PWTSR::ADAPTER_CONTACT_FORM_7 ) as $key ) {
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

  /**
   * Resolve a Contact Form 7 tracking special tag value.
   *
   * @param string $name  Special mail tag name.
   * @param array  $pairs Tracking key/value pairs.
   * @param bool   $html  Whether HTML output is requested.
   *
   * @return string|null
   */
  private function resolve_contact_form_7_tracking_tag_value( $name, $pairs, $html ) {
    $normalized = strtolower( trim( (string) $name ) );

    if ( in_array( $normalized, [ 'tracking-all', 'tracking-values', 'pwsr_transceiver' ], true ) ) {
      return $this->format_contact_form_7_tracking_pairs( $pairs, $html );
    }

    if ( 0 !== strpos( $normalized, 'tracking-' ) ) {
      return null;
    }

    $requested_key = substr( $normalized, strlen( 'tracking-' ) );
    if ( '' === $requested_key ) {
      return null;
    }

    foreach ( $pairs as $key => $value ) {
      if ( strtolower( (string) $key ) === $requested_key ) {
        if ( $html ) {
          return esc_html( $value );
        }

        return (string) $value;
      }
    }

    return '';
  }

}
