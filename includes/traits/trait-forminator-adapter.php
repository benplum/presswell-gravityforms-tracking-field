<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Forminator integration hooks.
 */
trait PWTSR_Forminator_Trait {

  /**
   * Initialize Forminator hooks when available.
   */
  public function maybe_bootstrap_forminator() {
    if ( ! defined( 'FORMINATOR_VERSION' ) && ! function_exists( 'forminator_custom_forms' ) ) {
      return;
    }

    add_action( 'forminator_custom_forms_enqueue_scripts', [ $this, 'maybe_enqueue_forminator_assets' ] );
    add_filter( 'forminator_data', [ $this, 'register_forminator_tracking_editor_variables' ], 20 );
    add_filter( 'forminator_render_form_markup', [ $this, 'inject_hidden_tracking_inputs' ], 20, 6 );
    add_filter( 'forminator_custom_form_submit_field_data', [ $this, 'append_tracking_to_entry' ], 20, 2 );
    add_filter( 'forminator_custom_form_entries_iterator', [ $this, 'append_tracking_to_entries_iterator' ], 20, 2 );
    add_filter( 'forminator_replace_custom_form_data', [ $this, 'replace_forminator_tracking_placeholders' ], 20, 6 );
  }

  /**
   * Enqueue front-end tracking script for Forminator forms.
   */
  public function maybe_enqueue_forminator_assets() {
    $this->enqueue_tracking_script( PWTSR::ADAPTER_FORMINATOR );
  }

  /**
   * Add tracking placeholders to Forminator's editor variable map.
   *
   * @param array $data Forminator localized admin data.
   *
   * @return array
   */
  public function register_forminator_tracking_editor_variables( $data ) {
    if ( ! is_array( $data ) ) {
      return $data;
    }

    if ( ! isset( $data['variables'] ) || ! is_array( $data['variables'] ) ) {
      $data['variables'] = [];
    }

    if ( ! isset( $data['variables']['tracking_all'] ) ) {
      $data['variables']['tracking_all'] = __( 'Tracking: All Signals', 'presswell-signal-relay' );
    }

    return $data;
  }

  /**
   * Inject hidden tracking inputs into rendered Forminator markup.
   *
   * @param string $html Rendered form markup.
   *
   * @return string
   */
  public function inject_hidden_tracking_inputs( $html, $form_fields = [], $form_type = '', $form_settings = [], $form_design = [], $render_id = '' ) {
    if ( $this->is_forminator_admin_editor_context() ) {
      return $html;
    }

    if ( false === stripos( $html, '</form>' ) ) {
      return $html;
    }

    if ( false !== strpos( $html, 'data-presswell-transceiver-adapter="forminator"' ) ) {
      return $html;
    }

    $inputs = [];
    foreach ( $this->service->get_tracking_keys( 'forminator' ) as $key ) {
      $inputs[] = $this->render_transceiver_input_markup(
        $key,
        $key,
        'presswell-forminator-' . sanitize_html_class( $key )
      );
    }

    if ( empty( $inputs ) ) {
      return $html;
    }

    $wrapper = $this->wrap_transceiver_inputs_markup( 'forminator', implode( '', $inputs ) );

    return preg_replace( '/<\/form>/i', $wrapper . '</form>', $html, 1 );
  }

  /**
   * Append tracking values onto the saved Forminator entry payload.
   *
   * @param array $field_data_array Submission payload.
   *
   * @return array
   */
  public function append_tracking_to_entry( $field_data_array, $form_id = 0 ) {
    if ( ! is_array( $field_data_array ) ) {
      $field_data_array = [];
    }

    $posted_values = $this->get_posted_tracking_values();
    if ( empty( $posted_values ) ) {
      return $field_data_array;
    }

    $name_index = [];
    foreach ( $field_data_array as $index => $row ) {
      if ( ! is_array( $row ) || empty( $row['name'] ) ) {
        continue;
      }

      $name_index[ (string) $row['name'] ] = $index;
    }

    foreach ( $this->service->get_tracking_keys( 'forminator' ) as $key ) {
      $value = isset( $posted_values[ $key ] ) ? $this->service->sanitize_tracking_value( $key, $posted_values[ $key ] ) : '';
      if ( '' === $value ) {
        continue;
      }

      if ( isset( $name_index[ $key ] ) ) {
        $field_data_array[ $name_index[ $key ] ]['value'] = $value;
        continue;
      }

      $field_data_array[] = [
        'name'  => $key,
        'value' => $value,
      ];
    }

    return $field_data_array;
  }

  /**
   * Add tracking pairs to Forminator admin entry detail iterator.
   *
   * @param array $iterator Iterator payload.
   * @param mixed $entry    Forminator entry model.
   *
   * @return array
   */
  public function append_tracking_to_entries_iterator( $iterator, $entry ) {
    if ( ! is_array( $iterator ) ) {
      return $iterator;
    }

    $pairs = $this->get_entry_tracking_values( $entry );
    if ( empty( $pairs ) ) {
      return $iterator;
    }

    if ( ! isset( $iterator['detail'] ) || ! is_array( $iterator['detail'] ) ) {
      $iterator['detail'] = [];
    }

    if ( ! isset( $iterator['detail']['items'] ) || ! is_array( $iterator['detail']['items'] ) ) {
      $iterator['detail']['items'] = [];
    }

    $existing_labels = [];
    foreach ( $iterator['detail']['items'] as $item ) {
      if ( is_array( $item ) && isset( $item['label'] ) ) {
        $existing_labels[] = (string) $item['label'];
      }
    }

    foreach ( $pairs as $key => $value ) {
      if ( in_array( $key, $existing_labels, true ) ) {
        continue;
      }

      $iterator['detail']['items'][] = [
        'type'        => 'text',
        'label'       => $key,
        'value'       => $value,
        'sub_entries' => [],
      ];
    }

    return $iterator;
  }

  /**
   * Read tracking values from the current request.
   *
   * @return array
   */
  private function get_posted_tracking_values() {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Forminator validates nonce before this callback runs.
    $request_post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : [];
    if ( empty( $request_post ) ) {
      return [];
    }

    $values = [];
    $nested = [];

    if ( isset( $request_post['data'] ) ) {
      $nested_raw = $request_post['data'];
      if ( is_array( $nested_raw ) ) {
        $nested = $nested_raw;
      }
    }

    foreach ( $this->service->get_tracking_keys( 'forminator' ) as $key ) {
      $raw = null;

      if ( isset( $request_post[ $key ] ) ) {
        $raw = $request_post[ $key ];
      } elseif ( isset( $nested[ $key ] ) ) {
        $raw = $nested[ $key ];
      }

      if ( null === $raw || is_array( $raw ) ) {
        continue;
      }

      $values[ $key ] = $raw;
    }

    return $values;
  }

  /**
   * Resolve tracking pairs from a Forminator entry model.
   *
   * @param mixed $entry Forminator entry model.
   *
   * @return array
   */
  private function get_entry_tracking_values( $entry ) {
    $pairs = [];

    foreach ( $this->service->get_tracking_keys( 'forminator' ) as $key ) {
      $value = '';

      if ( is_object( $entry ) && method_exists( $entry, 'get_meta' ) ) {
        $value = $entry->get_meta( $key, '' );
      }

      if ( '' === $value && is_object( $entry ) && isset( $entry->meta_data ) && is_array( $entry->meta_data ) ) {
        if ( isset( $entry->meta_data[ $key ] ) && is_array( $entry->meta_data[ $key ] ) && isset( $entry->meta_data[ $key ]['value'] ) ) {
          $value = $entry->meta_data[ $key ]['value'];
        }
      }

      $clean = $this->service->sanitize_tracking_value( $key, $value );
      if ( '' === $clean ) {
        continue;
      }

      $pairs[ $key ] = $clean;
    }

    return $pairs;
  }

  /**
   * Replace tracking placeholders for Forminator email/confirmation/integration pipelines.
   *
   * Supported placeholders:
   * - {tracking_all}
   * - {tracking_values}
   * - {tracking_<key>} e.g. {tracking_utm_source}
   *
   * @param string $content       Content with placeholders.
   * @param mixed  $custom_form   Forminator form model.
   * @param array  $prepared_data Submitted prepared data.
   * @param mixed  $entry         Forminator entry model.
   * @param array  $excluded      Excluded placeholders.
   * @param array  $custom_tags   Default Forminator custom tags map.
   *
   * @return string
   */
  public function replace_forminator_tracking_placeholders( $content, $custom_form, $prepared_data, $entry, $excluded, $custom_tags ) {
    if ( ! is_string( $content ) || false === strpos( $content, '{tracking' ) ) {
      return $content;
    }

    $pairs = $this->get_forminator_tracking_pairs_for_placeholders( $prepared_data, $entry );

    $all_lines = [];
    foreach ( $pairs as $key => $value ) {
      $all_lines[] = $key . ': ' . $value;
    }
    $all_value = implode( "\n", $all_lines );

    $content = $this->replace_forminator_placeholder( $content, '{tracking_all}', $all_value );
    $content = $this->replace_forminator_placeholder( $content, '{tracking_values}', $all_value );

    foreach ( $this->service->get_tracking_keys( 'forminator' ) as $key ) {
      $placeholder = '{tracking_' . $key . '}';
      $value       = isset( $pairs[ $key ] ) ? $pairs[ $key ] : '';
      $content     = $this->replace_forminator_placeholder( $content, $placeholder, $value );
    }

    return $content;
  }

  /**
   * Resolve tracking pairs from prepared data and entry models.
   *
   * @param array $prepared_data Submitted prepared data.
   * @param mixed $entry         Forminator entry model.
   *
   * @return array
   */
  private function get_forminator_tracking_pairs_for_placeholders( $prepared_data, $entry ) {
    $pairs = [];

    if ( ! is_array( $prepared_data ) ) {
      $prepared_data = [];
    }

    $entry_pairs = $this->get_entry_tracking_values( $entry );

    foreach ( $this->service->get_tracking_keys( 'forminator' ) as $key ) {
      $value = '';

      if ( isset( $prepared_data[ $key ] ) && ! is_array( $prepared_data[ $key ] ) ) {
        $value = $prepared_data[ $key ];
      } elseif ( isset( $entry_pairs[ $key ] ) ) {
        $value = $entry_pairs[ $key ];
      }

      $clean = $this->service->sanitize_tracking_value( $key, $value );
      if ( '' === $clean ) {
        continue;
      }

      $pairs[ $key ] = $clean;
    }

    return $pairs;
  }

  /**
   * Replace a placeholder in content including URL-embedded placeholders.
   *
   * @param string $content     Content string.
   * @param string $placeholder Placeholder token.
   * @param string $value       Replacement value.
   *
   * @return string
   */
  private function replace_forminator_placeholder( $content, $placeholder, $value ) {
    if ( false === strpos( $content, $placeholder ) ) {
      return $content;
    }

    if ( function_exists( 'forminator_replace_placeholder_in_urls' ) ) {
      $content = forminator_replace_placeholder_in_urls( $content, $placeholder, (string) $value );
    }

    return str_replace( $placeholder, (string) $value, $content );
  }

  /**
   * Detect whether current request is running inside Forminator admin/editor context.
   *
   * @return bool
   */
  private function is_forminator_admin_editor_context() {
    return is_admin();
  }
}
