<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_Submissions {

    public function __construct() {
        add_action( 'wp_ajax_formularios_submit', array( $this, 'handle_submission' ) );
        add_action( 'wp_ajax_nopriv_formularios_submit', array( $this, 'handle_submission' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_submissions_metabox' ) );
    }

    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'formularios_submissions';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL,
            data LONGTEXT NOT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            user_agent VARCHAR(255) DEFAULT '',
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY form_id (form_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Only mark as upgraded if the table actually exists now.
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $table_exists ) {
            update_option( 'formularios_db_version', FORMULARIOS_VERSION );
        }
    }

    public function handle_submission() {
        // Rate limiting: max 10 submissions per IP per minute
        $this->check_rate_limit();

        check_ajax_referer( 'formularios_submit', 'formularios_nonce' );

        // Verify CAPTCHA if enabled
        if ( Formularios_Captcha::is_enabled() ) {
            $captcha_token = isset( $_POST['formularios_captcha_token'] ) ? sanitize_text_field( $_POST['formularios_captcha_token'] ) : '';
            $captcha_result = Formularios_Captcha::verify_token( $captcha_token );

            // false = definitively failed (Google returned success:false or low score).
            // null  = could not verify (network error, empty token, no secret) — allow through.
            // true  = passed.
            if ( false === $captcha_result ) {
                wp_send_json_error( 'La verificacion de captcha fallo. Intenta de nuevo.' );
            }
        }

        $form_id = absint( $_POST['formularios_form_id'] ?? 0 );
        if ( ! $form_id || 'formulario' !== get_post_type( $form_id ) ) {
            wp_send_json_error( 'Formulario invalido.' );
        }

        $elements = get_post_meta( $form_id, '_formularios_elements', true );
        if ( empty( $elements ) ) {
            wp_send_json_error( 'El formulario no tiene campos.' );
        }

        $submission = array();
        $errors = array();

        foreach ( $elements as $el ) {
            if ( 'question' !== $el['type'] ) continue;

            $name = 'fm_field_' . sanitize_key( $el['id'] );
            $value = '';

            if ( 'file' === $el['input_type'] ) {
                // Handle multiple file uploads
                $value = $this->handle_file_uploads( $name, $el, $errors );
            } elseif ( 'checkbox' === $el['input_type'] ) {
                $value = isset( $_POST[ $name ] ) && is_array( $_POST[ $name ] )
                    ? array_map( 'sanitize_text_field', $_POST[ $name ] )
                    : array();
            } else {
                $value = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
            }

            // Validate required (skip files — handled in handle_file_upload)
            if ( 'file' !== $el['input_type'] && ! empty( $el['required'] ) ) {
                $empty = is_array( $value ) ? empty( $value ) : '' === trim( $value );
                if ( $empty ) {
                    $label = $el['label'] ?: 'Este campo';
                    $errors[ $name ] = sprintf( '%s es obligatorio.', $label );
                }
            }

            // Validate email
            if ( 'email' === $el['input_type'] && '' !== $value && ! is_email( $value ) ) {
                $errors[ $name ] = 'Ingresa una direccion de email valida.';
            }

            // Validate email confirmation
            if ( 'email' === $el['input_type'] && '' !== $value && is_email( $value ) ) {
                $confirm_key = $name . '_confirm';
                $confirm_val = isset( $_POST[ $confirm_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $confirm_key ] ) ) : '';
                if ( strtolower( $value ) !== strtolower( $confirm_val ) ) {
                    $errors[ $name ] = 'Los emails no coinciden.';
                }
            }

            // Validate against allowed options for select/radio/checkbox
            if ( in_array( $el['input_type'], array( 'select', 'radio' ), true ) && '' !== $value && ! empty( $el['options'] ) ) {
                $allowed = array_map( function( $opt ) {
                    return is_array( $opt ) ? ( $opt['label'] ?? '' ) : $opt;
                }, $el['options'] );
                if ( ! in_array( $value, $allowed, true ) ) {
                    $errors[ $name ] = 'Opcion no valida.';
                }
            }

            if ( 'checkbox' === $el['input_type'] && ! empty( $value ) && ! empty( $el['options'] ) ) {
                $allowed = array_map( function( $opt ) {
                    return is_array( $opt ) ? ( $opt['label'] ?? '' ) : $opt;
                }, $el['options'] );
                foreach ( $value as $v ) {
                    if ( ! in_array( $v, $allowed, true ) ) {
                        $errors[ $name ] = 'Opcion no valida.';
                        break;
                    }
                }
            }

            // Sanitize number type
            if ( 'number' === $el['input_type'] && '' !== $value ) {
                if ( ! is_numeric( $value ) ) {
                    $errors[ $name ] = 'Ingresa un numero valido.';
                }
            }

            $submission[] = array(
                'id'    => $el['id'],
                'label' => $el['label'],
                'type'  => $el['input_type'],
                'value' => $value,
            );
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( array( 'validation' => $errors ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'formularios_submissions';

        $ip = $this->get_client_ip();

        // Ensure table exists before inserting
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $table_exists ) {
            self::create_table();
        }

        $inserted = $wpdb->insert( $table, array(
            'form_id'      => $form_id,
            'data'         => wp_json_encode( $submission, JSON_UNESCAPED_UNICODE ),
            'ip_address'   => sanitize_text_field( $ip ),
            'user_agent'   => sanitize_text_field( substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255 ) ),
            'submitted_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%s', '%s' ) );

        if ( false === $inserted ) {
            wp_send_json_error( 'Error al guardar la respuesta. Intenta de nuevo.' );
        }

        // Sequential submission number for this form
        $submission_number = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE form_id = %d",
            $form_id
        ) );

        /**
         * Fires after a successful form submission.
         *
         * Wrapped in try-catch so that failures in notification hooks
         * (e.g. wp_mail via WP Mail SMTP) never prevent the success
         * response from reaching the browser.
         *
         * @param int   $form_id            The form post ID.
         * @param array $submission         The sanitized submission data.
         * @param array $elements           The form elements definition.
         * @param int   $submission_number  Sequential submission number for this form.
         */
        try {
            do_action( 'formularios_after_submission', $form_id, $submission, $elements, $submission_number );
        } catch ( \Throwable $e ) {
            error_log( 'Formularios: post-submission hook error — ' . $e->getMessage() );
        }

        wp_send_json_success( 'Respuesta guardada correctamente.' );
    }

    /**
     * Handle multiple file uploads for a single field.
     *
     * @param string $name   The field name (without [] suffix).
     * @param array  $el     The element definition.
     * @param array  &$errors Reference to errors array.
     * @return array|string Array of uploaded file URLs, or empty string if none.
     */
    private function handle_file_uploads( $name, $el, &$errors ) {
        // $_FILES with name="field[]" stores arrays for each property
        if ( empty( $_FILES[ $name ] ) || ! is_array( $_FILES[ $name ]['name'] ) ) {
            if ( ! empty( $el['required'] ) ) {
                $label = $el['label'] ?: 'Este campo';
                $errors[ $name ] = sprintf( '%s es obligatorio.', $label );
            }
            return '';
        }

        $files_raw = $_FILES[ $name ];
        $file_count = count( $files_raw['name'] );

        // Check if all files are UPLOAD_ERR_NO_FILE (no files selected)
        $all_empty = true;
        for ( $i = 0; $i < $file_count; $i++ ) {
            if ( UPLOAD_ERR_NO_FILE !== $files_raw['error'][ $i ] ) {
                $all_empty = false;
                break;
            }
        }

        if ( $all_empty ) {
            if ( ! empty( $el['required'] ) ) {
                $label = $el['label'] ?: 'Este campo';
                $errors[ $name ] = sprintf( '%s es obligatorio.', $label );
            }
            return '';
        }

        $max_size  = absint( $el['max_size'] ?? 5 );
        $max_bytes = $max_size * 1024 * 1024;
        $accepted  = array();
        if ( ! empty( $el['accepted_types'] ) ) {
            $accepted = array_map( 'trim', explode( ',', strtolower( $el['accepted_types'] ) ) );
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $urls = array();

        for ( $i = 0; $i < $file_count; $i++ ) {
            if ( UPLOAD_ERR_NO_FILE === $files_raw['error'][ $i ] ) {
                continue;
            }

            if ( UPLOAD_ERR_OK !== $files_raw['error'][ $i ] ) {
                $errors[ $name ] = 'Error al subir el archivo.';
                return $urls;
            }

            // Build a single-file array for wp_handle_upload
            $file = array(
                'name'     => $files_raw['name'][ $i ],
                'type'     => $files_raw['type'][ $i ],
                'tmp_name' => $files_raw['tmp_name'][ $i ],
                'error'    => $files_raw['error'][ $i ],
                'size'     => $files_raw['size'][ $i ],
            );

            // Validate file size
            if ( $file['size'] > $max_bytes ) {
                $errors[ $name ] = sprintf( 'El archivo "%s" excede el tamano maximo de %d MB.', $file['name'], $max_size );
                return $urls;
            }

            // Validate file type by extension
            if ( ! empty( $accepted ) ) {
                $ext = strtolower( '.' . pathinfo( $file['name'], PATHINFO_EXTENSION ) );
                if ( ! in_array( $ext, $accepted, true ) ) {
                    $errors[ $name ] = sprintf( 'Tipo de archivo no permitido: %s', $file['name'] );
                    return $urls;
                }
            }

            // WordPress file type checking
            $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
            if ( ! $check['type'] ) {
                $errors[ $name ] = sprintf( 'Tipo de archivo no permitido: %s', $file['name'] );
                return $urls;
            }

            $upload = wp_handle_upload( $file, array(
                'test_form' => false,
                'test_type' => true,
            ) );

            if ( isset( $upload['error'] ) ) {
                $errors[ $name ] = $upload['error'];
                return $urls;
            }

            $urls[] = $upload['url'];
        }

        return $urls;
    }

    /**
     * Simple rate limiting based on transients.
     */
    private function check_rate_limit() {
        $ip = $this->get_client_ip();
        $key = 'fm_rate_' . md5( $ip );
        $count = (int) get_transient( $key );

        if ( $count >= 10 ) {
            wp_send_json_error( 'Demasiados envios. Intenta de nuevo en unos minutos.' );
        }

        set_transient( $key, $count + 1, 60 );
    }

    /**
     * Get the client IP address safely.
     */
    private function get_client_ip() {
        // Only trust REMOTE_ADDR in standard setups
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }

    public function add_submissions_metabox() {
        add_meta_box(
            'formularios_submissions',
            'Respuestas',
            array( $this, 'render_submissions' ),
            'formulario',
            'normal',
            'default'
        );
    }

    public function render_submissions( $post ) {
        global $wpdb;
        $table = $wpdb->prefix . 'formularios_submissions';
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC LIMIT 50",
            $post->ID
        ) );

        if ( empty( $results ) ) {
            echo '<p style="color:#6B7280;padding:12px 0;">Todavia no hay respuestas.</p>';
            return;
        }

        echo '<div style="overflow-x:auto;">';
        echo '<table class="widefat striped" style="margin-top:8px;">';
        echo '<thead><tr>';
        echo '<th>#</th>';

        // Get column headers from first submission
        $first_data = json_decode( $results[0]->data, true );
        if ( is_array( $first_data ) ) {
            foreach ( $first_data as $field ) {
                echo '<th>' . esc_html( $field['label'] ?: $field['id'] ) . '</th>';
            }
        }
        echo '<th>Fecha</th>';
        echo '<th>IP</th>';
        echo '</tr></thead><tbody>';

        foreach ( $results as $idx => $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $idx + 1 ) . '</td>';
            $data = json_decode( $row->data, true );
            if ( is_array( $data ) ) {
                foreach ( $data as $field ) {
                    $val = $field['value'];
                    if ( 'file' === ( $field['type'] ?? '' ) && ! empty( $val ) ) {
                        $urls = is_array( $val ) ? $val : array( $val );
                        $links = array_map( function( $url ) {
                            return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Ver archivo</a>';
                        }, $urls );
                        echo '<td>' . implode( ', ', $links ) . '</td>';
                    } else {
                        if ( is_array( $val ) ) $val = implode( ', ', $val );
                        echo '<td>' . esc_html( $val ) . '</td>';
                    }
                }
            }
            echo '<td>' . esc_html( $row->submitted_at ) . '</td>';
            echo '<td>' . esc_html( $row->ip_address ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '<p style="color:#9CA3AF;font-size:12px;margin-top:8px;">' .
             sprintf( 'Mostrando las ultimas %d respuestas.', count( $results ) ) .
             '</p>';
    }
}
