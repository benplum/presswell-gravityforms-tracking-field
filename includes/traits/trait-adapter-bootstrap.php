<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Trait for lazy adapter loading and integration bootstrap.
 */
trait PWTSR_Adapter_Bootstrap_Trait {
  /**
   * Adapter registry.
   *
   * @var PWTSR_Adapter_Registry
   */
  private $adapter_registry;

  /**
   * Guard to ensure integrations only bootstrap once.
   *
   * @var bool
   */
  private $integrations_bootstrapped = false;

  /**
   * Build core dependencies and defer integration bootstrap.
   */
  protected function construct_adapter_bootstrap_trait() {
    $this->adapter_registry = new PWTSR_Adapter_Registry();
    $this->service = new PWTSR_Tracking_Service();
    
    // Delay integration registration until init so translated labels are not resolved too early.
    add_action( 'init', [ $this, 'bootstrap_integrations' ], 20 );
    add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_builder_icon_styles' ], 20 );
  }

  /**
   * Enqueue custom builder icon styles on supported form builder admin screens.
   */
  public function maybe_enqueue_builder_icon_styles() {
    if ( ! is_admin() ) {
      return;
    }

    $screen_id = '';
    if ( function_exists( 'get_current_screen' ) ) {
      $screen = get_current_screen();
      if ( is_object( $screen ) && isset( $screen->id ) ) {
        $screen_id = (string) $screen->id;
      }
    }

    $page_raw = filter_input( INPUT_GET, 'page', FILTER_UNSAFE_RAW );
    $page = is_string( $page_raw ) ? sanitize_key( wp_unslash( $page_raw ) ) : '';

    $is_fluent_screen  = false !== strpos( $screen_id, 'fluentform' )
      || false !== strpos( $page, 'fluentform' )
      || false !== strpos( $screen_id, 'fluent_forms' )
      || false !== strpos( $page, 'fluent_forms' );
    $is_formidable_screen = false !== strpos( $screen_id, 'formidable' )
      || false !== strpos( $page, 'formidable' )
      || 0 === strpos( $page, 'frm-' );
    $is_wpforms_screen = false !== strpos( $screen_id, 'wpforms' ) || false !== strpos( $page, 'wpforms' );

    if ( ! $is_fluent_screen && ! $is_formidable_screen && ! $is_wpforms_screen ) {
      return;
    }

    wp_enqueue_style(
      'presswell-signal-relay-builder-icons',
      $this->get_asset_url( 'css/builder-icons.css' ),
      [],
      PWTSR::VERSION
    );
  }

  /**
   * Load and register only adapters whose integration plugins are available.
   */
  public function bootstrap_integrations() {
    if ( $this->integrations_bootstrapped ) {
      return;
    }
    foreach ( $this->get_adapter_blueprints() as $blueprint ) {
      if ( empty( $blueprint['detector'] ) || ! is_callable( $blueprint['detector'] ) ) {
        continue;
      }
      if ( ! call_user_func( $blueprint['detector'] ) ) {
        continue;
      }
      if ( ! empty( $blueprint['files'] ) && is_array( $blueprint['files'] ) ) {
        foreach ( $blueprint['files'] as $file ) {
          require_once $this->get_plugin_path( $file );
        }
      }
      if ( empty( $blueprint['class'] ) || ! is_string( $blueprint['class'] ) ) {
        continue;
      }
      $class = $blueprint['class'];
      if ( ! class_exists( $class ) ) {
        continue;
      }
      $this->adapter_registry->add( new $class( $this->service ) );
    }
    $this->adapter_registry->register_all();
    $this->integrations_bootstrapped = true;
  }

  /**
   * Define adapter loading metadata for each supported integration.
   *
   * @return array[]
   */
  private function get_adapter_blueprints() {
    return [
      [
        'detector' => [ $this, 'is_contact_form_7_available' ],
        'class'    => 'PWTSR_Contact_Form_7_Adapter',
        'files'    => [
          'includes/traits/trait-adapter-assets.php',
          'includes/traits/trait-contact-form-7-adapter.php',
          'includes/adapters/class-contact-form-7-adapter.php',
        ],
      ],[
        'detector' => [ $this, 'is_fluent_forms_available' ],
        'class'    => 'PWTSR_Fluent_Forms_Adapter',
        'files'    => [
          'includes/traits/trait-adapter-assets.php',
          'includes/traits/trait-fluent-forms-adapter.php',
          'includes/adapters/class-fluent-forms-field.php',
          'includes/adapters/class-fluent-forms-adapter.php',
        ],
      ],
      [
        'detector' => [ $this, 'is_formidable_available' ],
        'class'    => 'PWTSR_Formidable_Adapter',
        'files'    => [
          'includes/traits/trait-adapter-assets.php',
          'includes/traits/trait-formidable-adapter.php',
          'includes/adapters/class-formidable-field.php',
          'includes/adapters/class-formidable-adapter.php',
        ],
      ],
      [
        'detector' => [ $this, 'is_forminator_available' ],
        'class'    => 'PWTSR_Forminator_Adapter',
        'files'    => [
          'includes/traits/trait-adapter-assets.php',
          'includes/traits/trait-forminator-adapter.php',
          'includes/adapters/class-forminator-adapter.php',
        ],
      ],
      [
        'detector' => [ $this, 'is_gravity_forms_available' ],
        'class'    => 'PWTSR_Gravity_Forms_Adapter',
        'files'    => [
          'includes/traits/trait-adapter-assets.php',
          'includes/traits/trait-gravity-forms-adapter.php',
          'includes/adapters/class-gravity-forms-field.php',
          'includes/adapters/class-gravity-forms-adapter.php',
        ],
      ],
      // [
      //   'detector' => [ $this, 'is_ninja_forms_available' ],
      //   'class'    => 'PWTSR_Ninja_Forms_Adapter',
      //   'files'    => [
      //     'includes/traits/trait-adapter-assets.php',
      //     'includes/traits/trait-ninja-forms-adapter.php',
      //     'includes/adapters/class-ninja-forms-adapter.php',
      //   ],
      // ],
      [
        'detector' => [ $this, 'is_wpforms_available' ],
        'class'    => 'PWTSR_WPForms_Adapter',
        'files'    => [
          'includes/traits/trait-adapter-assets.php',
          'includes/traits/trait-wpforms-adapter.php',
          'includes/adapters/class-wpforms-adapter.php',
        ],
      ],
    ];
  }
  
  /**
   * Determine whether Contact Form 7 is loaded.
   *
   * @return bool
   */
  private function is_contact_form_7_available() {
    return defined( 'WPCF7_VERSION' ) || function_exists( 'wpcf7' );
  }
  
  /**
   * Determine whether Fluent Forms is loaded.
   *
   * @return bool
   */
  private function is_fluent_forms_available() {
    return defined( 'FLUENTFORM_VERSION' ) || defined( 'FLUENTFORM' );
  }

  /**
   * Determine whether Formidable Forms is loaded.
   *
   * @return bool
   */
  private function is_formidable_available() {
    return defined( 'FORMIDABLE_VERSION' ) || class_exists( 'FrmAppHelper' );
  }
  
  /**
   * Determine whether Forminator is loaded.
   *
   * @return bool
   */
  private function is_forminator_available() {
    return defined( 'FORMINATOR_VERSION' ) || function_exists( 'forminator_custom_forms' );
  }

  /**
   * Determine whether Gravity Forms is loaded.
   *
   * @return bool
   */
  private function is_gravity_forms_available() {
    return class_exists( 'GFForms' );
  }
  
  // /**
  //  * Determine whether Ninja Forms is loaded.
  //  *
  //  * @return bool
  //  */
  // private function is_ninja_forms_available() {
  //   return defined( 'NINJA_FORMS_VERSION' ) || class_exists( 'Ninja_Forms' ) || function_exists( 'Ninja_Forms' );
  // }

  /**
   * Determine whether WPForms is loaded.
   *
   * @return bool
   */
  private function is_wpforms_available() {
    return defined( 'WPFORMS_VERSION' ) || function_exists( 'wpforms' ) || class_exists( '\\WPForms\\WPForms' );
  }
  
}
