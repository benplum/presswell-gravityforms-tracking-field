<?php
/**
 * Plugin Name: Presswell Tracking Signal Relay
 * Description: Capture UTM and click attribution parameters across a visitor session for supported form plugins.
 * Author: Presswell
 * Version: 1.0.0
 * Plugin URI: http://wordpress.org/plugins/
 * Author URI: http://presswell.co
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: presswell-signal-relay
 *
 * @package Presswell Tracking Signal Relay
 * @author Presswell
 *
 * Presswell Tracking Signal Relay is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * Presswell Tracking Signal Relay is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Presswell Tracking Signal Relay. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Manual QA sample query string for transceiver testing.
 * Paste onto any frontend URL to verify capture and form field relay.
 */
// ?utm_source=google&utm_medium=cpc&utm_campaign=winter_launch&utm_content=cta_banner&utm_term=snow_boots&gclid=Cj0KCQiAzbi-ABCD1234&fbclid=fb.9876543210XYZ&msclkid=MSCLKID123456&ttclid=TTCLID-987654&landing_page=https%3A%2F%2Fexample.com%2Fwinter-sale&landing_query=%3Fref%3Dpartner&referrer=https%3A%2F%2Fpartner-site.com&campaign_source=foobar

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

require_once __DIR__ . '/includes/helpers/class-constants.php';
require_once __DIR__ . '/includes/helpers/class-transceiver-markup.php';
require_once __DIR__ . '/includes/services/class-tracking-service.php';
require_once __DIR__ . '/includes/adapters/interface-form-adapter.php';
require_once __DIR__ . '/includes/adapters/class-adapter-registry.php';
require_once __DIR__ . '/includes/traits/trait-adapter-bootstrap.php';
require_once __DIR__ . '/includes/traits/trait-service-access.php';
require_once __DIR__ . '/includes/traits/trait-helpers.php';
require_once __DIR__ . '/includes/traits/trait-settings.php';
require_once __DIR__ . '/includes/traits/trait-pages.php';

if ( ! class_exists( 'Presswell_Tracking_Signal_Relay' ) ) {
  /**
   * Composition root for all plugin features.
   */
  final class Presswell_Tracking_Signal_Relay {
    use PWTSR_Adapter_Bootstrap_Trait;
    use PWTSR_Service_Access_Trait;
    use Presswell_Tracking_Signal_Relay_Helpers_Trait;
    use PWTSR_Settings_Trait;
    use PWTSR_Pages_Trait;
    
    const PLUGIN_FILE = __FILE__;

    /**
     * Cached singleton instance.
     *
      * @var Presswell_Tracking_Signal_Relay|null
     */
    private static $instance = null;

    /** Build core dependencies and defer integration bootstrap. */
    protected function __construct() {
      $this->construct_settings_trait();
      $this->construct_pages_trait();
      $this->construct_adapter_bootstrap_trait();
    }

    /**
     * Prevent cloning the singleton.
     */
    public function __clone() {}

    /**
     * Prevent unserializing the singleton.
     */
    public function __wakeup() {}

    /**
     * Return shared plugin instance.
     *
      * @return Presswell_Tracking_Signal_Relay
     */
    public static function instance() {
      if ( null === self::$instance ) {
        self::$instance = new self();
      }

      return self::$instance;
    }

  }
}

if ( ! function_exists( 'presswell_tracking_signal_relay' ) ) {
  /**
   * Access helper for plugin singleton.
   *
   * @return Presswell_Tracking_Signal_Relay
   */
  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Public helper name is intentional and kept for backward compatibility.
  function presswell_tracking_signal_relay() {
    return Presswell_Tracking_Signal_Relay::instance();
  }
}

presswell_tracking_signal_relay();
