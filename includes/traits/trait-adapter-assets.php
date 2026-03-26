<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Shared script registration/localization behavior for adapters.
 */
trait PWTSR_Adapter_Assets_Trait {

  /**
   * Cache debug mode state for the current request.
   *
   * @var bool|null
   */
  private $debug_field_mode = null;

  /**
   * Ensure runtime script is registered once.
   */
  private function register_tracking_assets() {
    if ( $this->assets_registered ) {
      return;
    }

    wp_register_script(
      PWTSR::ASSET_HANDLE_SCRIPT,
      plugin_dir_url( Presswell_Tracking_Signal_Relay::PLUGIN_FILE ) . 'assets/js/transceiver.js',
      [],
      PWTSR::VERSION,
      true
    );

    $this->assets_registered = true;
  }

  /**
   * Enqueue and localize tracking script.
   *
   * @param string $context Integration context.
   */
  protected function enqueue_tracking_script( $context = 'core' ) {
    $this->register_tracking_assets();

    wp_enqueue_script( PWTSR::ASSET_HANDLE_SCRIPT );
    $this->maybe_enqueue_debug_assets();

    if ( isset( $this->localized_objects[ PWTSR::JS_OBJECT ] ) ) {
      return;
    }

    wp_localize_script(
      PWTSR::ASSET_HANDLE_SCRIPT,
      PWTSR::JS_OBJECT,
      $this->service->get_client_config( $context )
    );

    $this->localized_objects[ PWTSR::JS_OBJECT ] = true;
  }

  /**
   * Enqueue debug styles that reveal tracking containers for privileged users.
   */
  private function maybe_enqueue_debug_assets() {
    if ( ! $this->is_debug_field_mode() ) {
      return;
    }

    static $debug_styles_enqueued = false;
    if ( $debug_styles_enqueued ) {
      return;
    }

    wp_enqueue_style(
      PWTSR::ASSET_HANDLE_DEBUG_STYLE,
      plugin_dir_url( Presswell_Tracking_Signal_Relay::PLUGIN_FILE ) . 'assets/css/debug.css',
      [],
      PWTSR::VERSION
    );

    wp_enqueue_script(
      PWTSR::ASSET_HANDLE_DEBUG_SCRIPT,
      plugin_dir_url( Presswell_Tracking_Signal_Relay::PLUGIN_FILE ) . 'assets/js/debug.js',
      [],
      PWTSR::VERSION,
      true
    );

    $debug_styles_enqueued = true;
  }

  /**
   * Determine whether debug field mode should be active for the current request.
   *
   * @return bool
   */
  protected function is_debug_field_mode() {
    if ( null !== $this->debug_field_mode ) {
      return $this->debug_field_mode;
    }

    $enabled = false;
    if ( function_exists( 'presswell_tracking_signal_relay' ) ) {
      $plugin = presswell_tracking_signal_relay();
      if ( $plugin && method_exists( $plugin, 'should_show_debug_styles' ) ) {
        $enabled = (bool) $plugin->should_show_debug_styles();
      }
    }

    $this->debug_field_mode = $enabled;

    return $this->debug_field_mode;
  }

  /**
   * Build shared transceiver wrapper classes.
   *
   * @param string $adapter Adapter slug for adapter-specific class name.
   *
   * @return string
   */
  protected function get_transceiver_classes( $adapter ) {
    $adapter = sanitize_html_class( (string) $adapter );

    return trim( 'presswell-transceiver presswell-' . $adapter . '-transceiver ' );
  }

  /**
   * Render a tracking input that switches between hidden and debug-friendly markup.
   *
   * @param string $key    Tracking key label.
   * @param string $name   Input name attribute.
   * @param string $id     Input id attribute.
   * @param string $value  Input value.
   *
   * @return string
   */
  protected function render_transceiver_input_markup( $key, $name, $id, $value = '' ) {
    if ( $this->is_debug_field_mode() ) {
      return sprintf(
        '<div class="presswell-debug-field-row"><label class="presswell-debug-field-label" for="%1$s">%2$s</label><input type="text" id="%1$s" name="%3$s" value="%4$s" readonly="readonly" data-presswell-transceiver="%5$s" /></div>',
        esc_attr( $id ),
        esc_html( $key ),
        esc_attr( $name ),
        esc_attr( $value ),
        esc_attr( $key )
      );
    }

    return sprintf(
      '<input type="hidden" id="%1$s" name="%2$s" value="%3$s" data-presswell-transceiver="%4$s" />',
      esc_attr( $id ),
      esc_attr( $name ),
      esc_attr( $value ),
      esc_attr( $key )
    );
  }

  /**
   * Wrap tracking inputs in a shared transceiver container.
   *
   * @param string $adapter Adapter slug.
   * @param string $inputs  Inputs HTML.
   *
   * @return string
   */
  protected function wrap_transceiver_inputs_markup( $adapter, $inputs ) {
    return PWTSR_Transceiver_Markup::render_wrapper(
      $this->get_transceiver_classes( $adapter ),
      $adapter,
      $inputs,
      $this->is_debug_field_mode()
    );
  }
}
