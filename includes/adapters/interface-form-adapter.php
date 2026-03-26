<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Contract implemented by all form integration adapters.
 */
interface PWTSR_Form_Adapter_Interface {

  /**
   * Return the adapter key used for registration and lookup.
   *
   * @return string
   */
  public function key();

  /**
   * Register all hooks for this adapter.
   */
  public function register();
}
