<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Contact Form 7 adapter for Presswell Tracking Signal Relay.
 */
class PWTSR_Contact_Form_7_Adapter implements PWTSR_Form_Adapter_Interface {
  use PWTSR_Adapter_Assets_Trait;
  use PWTSR_Contact_Form_7_Trait;

  /**
   * Shared tracking service instance.
   *
   * @var PWTSR_Tracking_Service
   */
  private $service;

  /**
   * Whether assets are registered for this request.
   *
   * @var bool
   */
  private $assets_registered = false;

  /**
   * Tracks localized JS objects to avoid duplicate calls.
   *
   * @var array
   */
  private $localized_objects = [];

  /**
   * @param PWTSR_Tracking_Service $service Shared tracking service.
   */
  public function __construct( PWTSR_Tracking_Service $service ) {
    $this->service = $service;
  }

  /**
   * Return adapter key.
   *
   * @return string
   */
  public function key() {
    return PWTSR::ADAPTER_CONTACT_FORM_7;
  }

  /**
   * Register adapter boot hooks.
   */
  public function register() {
    $this->maybe_bootstrap_contact_form_7();
  }
}
