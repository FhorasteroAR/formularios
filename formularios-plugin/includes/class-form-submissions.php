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

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL,
            data LONGTEXT NOT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function handle_submission() {
        check_ajax_referer( 'formularios_submit', 'formularios_nonce' );

        $form_id = absint( $_POST['formularios_form_id'] ?? 0 );
        if ( ! $form_id || 'formulario' !== get_post_type( $form_id ) ) {
            wp_send_json_error( __( 'Invalid form.', 'formularios' ) );
        }

        $elements = get_post_meta( $form_id, '_formularios_elements', true );
        if ( empty( $elements ) ) {
            wp_send_json_error( __( 'Form has no fields.', 'formularios' ) );
        }

        $submission = array();
        $errors = array();

        foreach ( $elements as $el ) {
            if ( 'question' !== $el['type'] ) continue;

            $name = 'fm_field_' . sanitize_key( $el['id'] );
            $value = '';

            if ( 'checkbox' === $el['input_type'] ) {
                $value = isset( $_POST[ $name ] ) && is_array( $_POST[ $name ] )
                    ? array_map( 'sanitize_text_field', $_POST[ $name ] )
                    : array();
            } else {
                $value = isset( $_POST[ $name ] ) ? sanitize_text_field( $_POST[ $name ] ) : '';
            }

            // Validate required
            if ( ! empty( $el['required'] ) ) {
                $empty = is_array( $value ) ? empty( $value ) : '' === trim( $value );
                if ( $empty ) {
                    $errors[ $name ] = sprintf(
                        __( '%s is required.', 'formularios' ),
                        $el['label'] ?: __( 'This field', 'formularios' )
                    );
                }
            }

            // Validate email
            if ( 'email' === $el['input_type'] && '' !== $value && ! is_email( $value ) ) {
                $errors[ $name ] = __( 'Please enter a valid email address.', 'formularios' );
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

        $wpdb->insert( $table, array(
            'form_id'      => $form_id,
            'data'         => wp_json_encode( $submission ),
            'ip_address'   => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
            'submitted_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%s' ) );

        wp_send_json_success( __( 'Submission saved.', 'formularios' ) );
    }

    public function add_submissions_metabox() {
        add_meta_box(
            'formularios_submissions',
            __( 'Submissions', 'formularios' ),
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
            echo '<p style="color:#6B7280;padding:12px 0;">' . esc_html__( 'No submissions yet.', 'formularios' ) . '</p>';
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
        echo '<th>' . esc_html__( 'Date', 'formularios' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $results as $idx => $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $idx + 1 ) . '</td>';
            $data = json_decode( $row->data, true );
            if ( is_array( $data ) ) {
                foreach ( $data as $field ) {
                    $val = $field['value'];
                    if ( is_array( $val ) ) $val = implode( ', ', $val );
                    echo '<td>' . esc_html( $val ) . '</td>';
                }
            }
            echo '<td>' . esc_html( $row->submitted_at ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '<p style="color:#9CA3AF;font-size:12px;margin-top:8px;">' .
             sprintf( esc_html__( 'Showing latest %d submissions.', 'formularios' ), count( $results ) ) .
             '</p>';
    }
}
