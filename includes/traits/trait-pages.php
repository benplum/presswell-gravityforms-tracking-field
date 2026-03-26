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
  }

  /**
   * Register settings page under Settings.
   */
  public function register_admin_pages() {
    add_options_page(
      __( 'Tracking Signal Relay', PWTSR::TEXT_DOMAIN ),
      __( 'Tracking Signal Relay', PWTSR::TEXT_DOMAIN ),
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
      esc_html__( 'Settings', PWTSR::TEXT_DOMAIN )
    );

    array_unshift( $links, $settings_link );

    return $links;
  }
}
