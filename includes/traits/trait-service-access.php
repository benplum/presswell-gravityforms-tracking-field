<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Trait for exposing service and adapters accessors.
 */
trait PWTSR_Service_Access_Trait {
    
  /**
  * Shared tracking service instance.
  *
  * @var PWTSR_Tracking_Service
  */
  private $service;

  /**
   * Expose service for supporting classes.
   *
   * @return PWTSR_Tracking_Service
   */
  public function get_service() {
    return $this->service;
  }

  /**
   * Expose registered adapters.
   *
   * @return PWTSR_Form_Adapter_Interface[]
   */
  public function get_adapters() {
    return $this->adapter_registry->all();
  }
}
