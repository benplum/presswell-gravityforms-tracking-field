<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Settings registration and rendering helpers.
 */
trait PWTSR_Settings_Trait {
  /**
   * Attach settings hooks.
   */
  protected function construct_settings_trait() {
    add_action( 'admin_init', [ $this, 'register_settings' ] );
  }

  /**
   * Register plugin settings section and fields.
   */
  public function register_settings() {
    register_setting(
      'pwtsr_settings_group',
      PWTSR::SETTINGS_KEY,
      [
        'type'              => 'array',
        'sanitize_callback' => [ $this, 'sanitize_settings' ],
        'default'           => $this->get_default_settings(),
      ]
    );

    add_settings_section(
      'pwtsr_main_section',
      __( 'Settings', PWTSR::TEXT_DOMAIN ),
      '__return_false',
      PWTSR::SETTINGS_PAGE_SLUG
    );

    add_settings_field(
      'debug_mode',
      __( 'Debug Mode', PWTSR::TEXT_DOMAIN ),
      [ $this, 'render_debug_mode_field' ],
      PWTSR::SETTINGS_PAGE_SLUG,
      'pwtsr_main_section'
    );
  }

  /**
   * Sanitize the settings payload.
   *
   * @param array $input Raw settings payload.
   *
   * @return array
   */
  public function sanitize_settings( $input ) {
    $input = is_array( $input ) ? $input : [];

    return [
      'debug_mode' => ! empty( $input['debug_mode'] ) ? 'on' : '',
    ];
  }

  /**
   * Get default settings.
   *
   * @return array
   */
  public function get_default_settings() {
    return [
      'debug_mode' => '',
    ];
  }

  /**
   * Retrieve settings merged with defaults.
   *
   * @return array
   */
  public function get_settings() {
    return wp_parse_args( get_option( PWTSR::SETTINGS_KEY, [] ), $this->get_default_settings() );
  }

  /**
   * Whether debug mode is enabled by setting.
   *
   * @return bool
   */
  public function is_debug_mode_enabled() {
    $settings = $this->get_settings();

    return ! empty( $settings['debug_mode'] );
  }

  /**
   * Whether debug styles should be shown to the current visitor.
   *
   * @return bool
   */
  public function should_show_debug_styles() {
    return $this->is_debug_mode_enabled() && is_user_logged_in() && current_user_can( 'edit_others_posts' );
  }

  /**
   * Render the debug mode settings field.
   */
  public function render_debug_mode_field() {
    $settings = $this->get_settings();
    ?>
    <label>
      <input type="checkbox" name="<?php echo esc_attr( PWTSR::SETTINGS_KEY ); ?>[debug_mode]" value="on" <?php checked( ! empty( $settings['debug_mode'] ) ); ?> />
      <?php echo esc_html__( 'Show tracking field containers on the frontend for editors and admins.', PWTSR::TEXT_DOMAIN ); ?>
    </label>
    <?php
  }

  /**
   * Render plugin settings page.
   */
  public function render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }

    $this->render_view( 'settings-page.php' );
  }
}
