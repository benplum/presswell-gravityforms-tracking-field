<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Gravity Forms adapter for Presswell Tracking Signal Relay.
 */
class PWTSR_Gravity_Forms_Adapter implements PWTSR_Form_Adapter_Interface {
  use PWTSR_Adapter_Assets_Trait;
  use PWTSR_Gravity_Forms_Trait;

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
   * Whether inline visibility styles were enqueued.
   *
   * @var bool
   */
  private $styles_enqueued = false;

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
    return PWTSR::ADAPTER_GRAVITY_FORMS;
  }

  /**
   * Register adapter boot hooks.
   */
  public function register() {
    $this->maybe_bootstrap_gravity_forms();
  }
}
