<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Admin Settings -> Tracking Signal Relay markup.
 */
?>
<div class="wrap">
  <h1><?php echo esc_html__( 'Tracking Signal Relay', PWTSR::TEXT_DOMAIN ); ?></h1>
  <form method="post" action="options.php">
    <?php
      settings_fields( 'pwtsr_settings_group' );
      do_settings_sections( PWTSR::SETTINGS_PAGE_SLUG );
      submit_button();
    ?>
  </form>
</div>
