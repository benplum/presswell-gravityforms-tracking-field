<?php
/**
 * Tests plugin bootstrap, singleton wiring, and hook registration.
 */

class CorePluginIntegrationTest extends WP_UnitTestCase {
  /** @var Presswell_Tracking_Signal_Relay */
  protected $plugin;

  protected function setUp(): void {
    parent::setUp();
    $this->plugin = presswell_tracking_signal_relay();
  }

  public function test_singleton_returns_same_instance() {
    $this->assertSame( $this->plugin, presswell_tracking_signal_relay() );
  }

  public function test_core_admin_and_bootstrap_hooks_are_registered() {
    $this->assertNotFalse( has_action( 'admin_init', [ $this->plugin, 'register_settings' ] ) );
    $this->assertNotFalse( has_action( 'admin_menu', [ $this->plugin, 'register_admin_pages' ] ) );
    $this->assertNotFalse( has_action( 'init', [ $this->plugin, 'bootstrap_integrations' ] ) );
    $this->assertNotFalse( has_action( 'admin_enqueue_scripts', [ $this->plugin, 'maybe_enqueue_builder_icon_styles' ] ) );

    $filter = 'plugin_action_links_' . plugin_basename( Presswell_Tracking_Signal_Relay::PLUGIN_FILE );
    $this->assertNotFalse( has_filter( $filter, [ $this->plugin, 'add_settings_action_link' ] ) );
  }

  public function test_service_accessor_returns_tracking_service() {
    $service = $this->plugin->get_service();

    $this->assertInstanceOf( 'PWTSR_Tracking_Service', $service );
  }

  public function test_settings_action_link_is_prepended() {
    $links = [ '<a href="plugins.php">Deactivate</a>' ];

    $result = $this->plugin->add_settings_action_link( $links );

    $this->assertCount( 2, $result );
    $this->assertStringContainsString( PWTSR::SETTINGS_PAGE_URL, $result[0] );
    $this->assertStringContainsString( 'Deactivate', $result[1] );
  }
}
