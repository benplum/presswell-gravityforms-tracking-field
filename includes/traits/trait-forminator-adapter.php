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
    add_filter( 'forminator_render_form_markup', [ $this, 'inject_hidden_tracking_inputs' ], 20, 6 );
    add_filter( 'forminator_custom_form_submit_field_data', [ $this, 'append_tracking_to_entry' ], 20, 2 );
    add_filter( 'forminator_custom_form_entries_iterator', [ $this, 'append_tracking_to_entries_iterator' ], 20, 2 );
  }

  /**
   * Enqueue front-end tracking script for Forminator forms.
   */
  public function maybe_enqueue_forminator_assets() {
    $this->enqueue_tracking_script( PWTSR::ADAPTER_FORMINATOR );
  }

  /**
   * Inject hidden tracking inputs into rendered Forminator markup.
   *
   * @param string $html Rendered form markup.
   *
   * @return string
   */
  public function inject_hidden_tracking_inputs( $html, $form_fields = [], $form_type = '', $form_settings = [], $form_design = [], $render_id = '' ) {
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
    if ( empty( $_POST ) || ! is_array( $_POST ) ) {
      return [];
    }

    $values = [];
    $nested = [];

    if ( isset( $_POST['data'] ) ) {
      $nested_raw = wp_unslash( $_POST['data'] );
      if ( is_array( $nested_raw ) ) {
        $nested = $nested_raw;
      }
    }

    foreach ( $this->service->get_tracking_keys( 'forminator' ) as $key ) {
      $raw = null;

      if ( isset( $_POST[ $key ] ) ) {
        $raw = wp_unslash( $_POST[ $key ] );
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
}
