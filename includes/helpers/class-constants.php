<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Central constants singleton for plugin-wide identifiers.
 */
final class PWTSR {

  const VERSION = '1.0.0';
  const TEXT_DOMAIN = 'presswell-signal-relay';
  const SETTINGS_KEY = 'pwtsr_settings';
  const SETTINGS_PAGE_SLUG = 'presswell-signal-relay';
  const SETTINGS_PAGE_URL = 'options-general.php?page=' . self::SETTINGS_PAGE_SLUG;
  const SETTINGS_PAGE_SCREEN_ID = 'settings_page_' . self::SETTINGS_PAGE_SLUG;

  const FIELD_TYPE = 'presswell_transceiver';

  const ASSET_HANDLE_SCRIPT = 'presswell-signal-relay-js';
  const ASSET_HANDLE_STYLE = 'presswell-signal-relay-css';
  const ASSET_HANDLE_DEBUG_STYLE = 'presswell-signal-relay-debug-css';
  const ASSET_HANDLE_DEBUG_SCRIPT = 'presswell-signal-relay-debug-js';

  const STORAGE_KEY = 'pwSignalRelay';
  const TTL_SECONDS = 3600;
  const MAX_VALUE_LENGTH = 1024;

  const JS_OBJECT = 'presswellSignalRelayConfig';

  const ADAPTER_CONTACT_FORM_7 = 'contactform7';
  const ADAPTER_FLUENT_FORMS = 'fluentforms';
  const ADAPTER_FORMIDABLE = 'formidable';
  const ADAPTER_FORMINATOR = 'forminator';
  const ADAPTER_GRAVITY_FORMS = 'gravityforms';
  // const ADAPTER_NINJA_FORMS = 'ninjaforms';
  const ADAPTER_WPFORMS = 'wpforms';
  
  const DEFAULT_TRACKING_KEYS = [
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'utm_content',
    'utm_term',
    'gclid',
    'fbclid',
    'msclkid',
    'ttclid',
    'landing_page',
    'landing_query',
    'referrer',
  ];
  
}
