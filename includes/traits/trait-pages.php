<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Registers plugin admin pages.
 */
trait PWTSR_Pages_Trait {
  /**
   * Attach menu hooks.
   */
  protected function construct_pages_trait() {
    add_action( 'admin_menu', [ $this, 'register_admin_pages' ] );
    add_filter( 'plugin_action_links_' . plugin_basename( Presswell_Tracking_Signal_Relay::PLUGIN_FILE ), [ $this, 'add_settings_action_link' ] );
    add_filter( 'admin_footer_text', [ $this, 'filter_presswell_admin_footer_text' ], 20 );
    add_filter( 'update_footer', [ $this, 'filter_presswell_admin_version_text' ], 20 );
  }

  /**
   * Register settings page under Settings.
   */
  public function register_admin_pages() {
    add_options_page(
      __( 'Tracking Signal Relay', 'presswell-signal-relay' ),
      __( 'Tracking Signal Relay', 'presswell-signal-relay' ),
      'manage_options',
      PWTSR::SETTINGS_PAGE_SLUG,
      [ $this, 'render_settings_page' ]
    );
  }

  /**
   * Add a Settings shortcut on the Plugins screen.
   *
   * @param array $links Existing plugin action links.
   *
   * @return array
   */
  public function add_settings_action_link( $links ) {
    $settings_link = sprintf(
      '<a href="%s">%s</a>',
      esc_url( admin_url( PWTSR::SETTINGS_PAGE_URL ) ),
      esc_html__( 'Settings', 'presswell-signal-relay' )
    );

    array_unshift( $links, $settings_link );

    return $links;
  }

  /**
   * Whether the current admin screen belongs to this plugin.
   *
   * @return bool
   */
  protected function is_presswell_admin_screen() {
    if ( ! function_exists( 'get_current_screen' ) ) {
      return false;
    }

    $screen = get_current_screen();
    if ( ! $screen || empty( $screen->id ) ) {
      return false;
    }

    return PWTSR::SETTINGS_PAGE_SCREEN_ID === $screen->id;
  }

  /**
   * Replace default admin footer text on plugin admin screens.
   *
   * @param string $footer_text Existing footer text.
   *
   * @return string
   */
  public function filter_presswell_admin_footer_text( $footer_text ) {
    if ( ! $this->is_presswell_admin_screen() ) {
      return $footer_text;
    }

    return '<a href="https://presswell.co" target="_blank" rel="noopener">Presswell Supply Co.</a> &bull; Quality Digital Goods';
  }

  /**
   * Replace default version footer text on plugin admin screens.
   *
   * @param string $version_text Existing version text.
   *
   * @return string
   */
  public function filter_presswell_admin_version_text( $version_text ) {
    if ( ! $this->is_presswell_admin_screen() ) {
      return $version_text;
    }

    return 'Tracking Signal Relay v' . PWTSR::VERSION;
  }
}
