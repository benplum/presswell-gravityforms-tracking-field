<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Shared transceiver wrapper markup helpers.
 */
final class PWTSR_Transceiver_Markup {

  /**
   * Determine whether debug panel should start closed based on cookie state.
   *
   * @return bool
   */
  public static function is_debug_panel_closed_from_cookie() {
    if ( empty( $_COOKIE['pwsrDebugClosed'] ) ) {
      return false;
    }

    $raw = sanitize_text_field( wp_unslash( $_COOKIE['pwsrDebugClosed'] ) );

    return '1' === (string) $raw;
  }

  /**
   * Render wrapper markup for tracking inputs.
   *
   * @param string $classes    Space-separated wrapper classes.
   * @param string $adapter    Adapter identifier.
   * @param string $inputs     Inputs markup.
   * @param bool   $debug_mode Whether debug mode is active.
   *
   * @return string
   */
  public static function render_wrapper( $classes, $adapter, $inputs, $debug_mode ) {
    if ( '' === trim( (string) $inputs ) ) {
      return '';
    }

    $adapter_attr = sanitize_html_class( (string) $adapter );
    $classes_attr = esc_attr( (string) $classes );

    if ( $debug_mode ) {
      $is_open = ! self::is_debug_panel_closed_from_cookie();

      return sprintf(
        '<div class="%1$s" data-presswell-transceiver="1" data-presswell-transceiver-adapter="%2$s"><button type="button" class="presswell-debug-toggle" aria-expanded="%3$s"><span class="presswell-debug-toggle-label">Tracking Signals</span><span class="presswell-debug-toggle-arrow" aria-hidden="true"></span></button><div class="presswell-debug-fields"%4$s>%5$s</div></div>',
        $classes_attr,
        esc_attr( $adapter_attr ),
        $is_open ? 'true' : 'false',
        $is_open ? '' : ' hidden',
        $inputs
      );
    }

    return sprintf(
      '<div class="%1$s" data-presswell-transceiver="1" data-presswell-transceiver-adapter="%2$s" style="display:none" aria-hidden="true">%3$s</div>',
      $classes_attr,
      esc_attr( $adapter_attr ),
      $inputs
    );
  }
}
