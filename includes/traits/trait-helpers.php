<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Shared utility helpers for locating plugin assets and rendering templates.
 * Adapted from PWATG_Helpers_Trait for Tracking Signal Relay namespace.
 */
trait Presswell_Tracking_Signal_Relay_Helpers_Trait {
  /**
   * Build an absolute path inside the plugin directory.
   *
   * @param string $path Optional relative path.
   *
   * @return string
   */
  protected function get_plugin_path( $path = '' ) {
    $base = plugin_dir_path( Presswell_Tracking_Signal_Relay::PLUGIN_FILE );
    if ( '' === $path ) {
      return $base;
    }
    return $base . ltrim( $path, '/' );
  }

  /**
   * Build a public URL inside the plugin directory.
   *
   * @param string $path Optional relative path.
   *
   * @return string
   */
  protected function get_plugin_url( $path = '' ) {
    $base = plugin_dir_url( Presswell_Tracking_Signal_Relay::PLUGIN_FILE );
    if ( '' === $path ) {
      return $base;
    }
    return $base . ltrim( $path, '/' );
  }

  /**
   * Build a versioned asset URL under the assets/ directory.
   *
   * @param string $relative_path Relative asset path.
   *
   * @return string
   */
  protected function get_asset_url( $relative_path ) {
    return $this->get_plugin_url( 'assets/' . ltrim( $relative_path, '/' ) );
  }

  /**
   * Render a view file from includes/views.
   *
   * @param string $view Relative view filename.
   * @param array  $vars Optional data extracted into scope.
   */
  protected function render_view( $view, $vars = [] ) {
    $file = $this->get_plugin_path( 'includes/views/' . ltrim( $view, '/' ) );
    if ( ! file_exists( $file ) ) {
      return;
    }

    if ( is_array( $vars ) && ! empty( $vars ) ) {
      extract( $vars, EXTR_SKIP );
    }

    include $file;
  }
}
