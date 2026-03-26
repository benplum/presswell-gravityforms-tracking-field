<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Formidable adapter for Presswell Tracking Signal Relay.
 */
class PWTSR_Formidable_Adapter implements PWTSR_Form_Adapter_Interface {
  use PWTSR_Adapter_Assets_Trait;
  use PWTSR_Formidable_Trait;

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
    return PWTSR::ADAPTER_FORMIDABLE;
  }

  /**
   * Register adapter boot hooks.
   */
  public function register() {
    $this->maybe_bootstrap_formidable();
  }
}
