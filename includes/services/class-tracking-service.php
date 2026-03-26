<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Shared tracking service used by all form adapters.
 */
class PWTSR_Tracking_Service {

  /**
   * Return tracking keys for a specific adapter context.
   *
   * @param string $context Integration context.
   *
   * @return string[]
   */
  public function get_tracking_keys( $context = 'core' ) {
    $keys = apply_filters( 'presswell_tracking_signal_relay_tracking_keys', PWTSR::DEFAULT_TRACKING_KEYS, $context );

    return $this->sanitize_keys( $keys );
  }

  /**
   * Return TTL seconds for a specific adapter context.
   *
   * @param string $context Integration context.
   *
   * @return int
   */
  public function get_ttl_seconds( $context = 'core' ) {
    $ttl = (int) apply_filters( 'presswell_tracking_signal_relay_tracking_ttl', PWTSR::TTL_SECONDS, $context );

    return $ttl > 0 ? $ttl : PWTSR::TTL_SECONDS;
  }

  /**
   * Build client-side config payload.
   *
   * @param string $context Integration context.
   *
   * @return array
   */
  public function get_client_config( $context = 'core' ) {
    $storage_key = (string) apply_filters( 'presswell_tracking_signal_relay_storage_key', PWTSR::STORAGE_KEY, $context );
    if ( '' === $storage_key ) {
      $storage_key = PWTSR::STORAGE_KEY;
    }

    return [
      'storageKey'  => $storage_key,
      'ttl'         => $this->get_ttl_seconds( $context ),
      'transceiverKeys' => $this->get_tracking_keys( $context ),
    ];
  }

  /**
   * Sanitize a single tracking value and enforce max length.
   *
   * @param string $key   Tracking key.
   * @param mixed  $value Tracking value.
   *
   * @return string
   */
  public function sanitize_tracking_value( $key, $value ) {
    if ( ! is_scalar( $value ) ) {
      return '';
    }

    $clean = sanitize_textarea_field( (string) $value );
    if ( '' === $clean ) {
      return '';
    }

    $clean = $this->truncate_value( $clean );
    if ( '' === $clean ) {
      return '';
    }

    if ( in_array( $key, [ 'landing_page', 'referrer' ], true ) ) {
      $clean = esc_url_raw( $clean );
      return $this->truncate_value( $clean );
    }

    return $clean;
  }

  /**
   * Clamp string values to the plugin max length.
   *
   * @param string $value Raw value.
   *
   * @return string
   */
  public function truncate_value( $value ) {
    $value = (string) $value;
    if ( '' === $value ) {
      return '';
    }

    if ( function_exists( 'mb_substr' ) ) {
      return mb_substr( $value, 0, PWTSR::MAX_VALUE_LENGTH );
    }

    return substr( $value, 0, PWTSR::MAX_VALUE_LENGTH );
  }

  /**
   * Normalize and de-duplicate tracking keys.
   *
   * @param mixed $keys Raw key list.
   *
   * @return string[]
   */
  private function sanitize_keys( $keys ) {
    if ( ! is_array( $keys ) ) {
      return PWTSR::DEFAULT_TRACKING_KEYS;
    }

    $sanitized = [];
    foreach ( $keys as $key ) {
      if ( ! is_scalar( $key ) ) {
        continue;
      }

      $clean = sanitize_key( (string) $key );
      if ( '' === $clean ) {
        continue;
      }

      $sanitized[] = $clean;
    }

    $sanitized = array_values( array_unique( $sanitized ) );

    return ! empty( $sanitized ) ? $sanitized : PWTSR::DEFAULT_TRACKING_KEYS;
  }
}
