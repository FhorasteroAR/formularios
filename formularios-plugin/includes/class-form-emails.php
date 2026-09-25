<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_Emails {

    public function __construct() {
        add_action( 'formularios_after_submission', array( $this, 'send_notifications' ), 10, 4 );
        add_action( 'wp_ajax_formularios_email_preview', array( $this, 'ajax_preview' ) );
    }

    /* ------------------------------------------------------------------
       Configuracion por formulario
    ------------------------------------------------------------------ */

    /**
     * Valores por defecto de cada email. "admin" es el que reciben los
     * destinatarios configurados; "respondent" el que recibe quien completa.
     */
    public static function defaults() {
        return array(
            'admin' => array(
                'subject'         => 'Nueva respuesta #{numero}: {formulario}',
                'heading'         => 'Nueva respuesta recibida (#{numero})',
                'intro'           => '',
                'footer'          => 'Respuesta #{numero} — Enviado el {fecha}',
                'include_answers' => '1',
                'header_color'    => '#4F46E5',
                'reply_to'        => '1',
            ),
            'respondent' => array(
                'subject'         => 'Copia de tu respuesta #{numero}: {formulario}',
                'heading'         => 'Copia de tu respuesta (#{numero})',
                'intro'           => '',
                'footer'          => 'Respuesta #{numero} — Gracias por completar el formulario. Esta es una copia de tus respuestas.',
                'include_answers' => '1',
                'header_color'    => '#4F46E5',
                'reply_to'        => '0',
            ),
        );
    }

    /**
     * Configuracion de emails de un formulario, completada con los valores por defecto.
     */
    public static function get_config( $form_id ) {
        $saved    = get_post_meta( $form_id, '_formularios_emails', true );
        $saved    = is_array( $saved ) ? $saved : array();
        $defaults = self::defaults();
        $config   = array();
        foreach ( $defaults as $key => $values ) {
            $config[ $key ] = wp_parse_args( $saved[ $key ] ?? array(), $values );
        }
        return $config;
    }

    /**
     * Sanitiza la configuracion enviada desde la solapa de emails.
     */
    public static function sanitize_config( $raw ) {
        $clean = array();
        foreach ( self::defaults() as $key => $defaults ) {
            $in = is_array( $raw[ $key ] ?? null ) ? $raw[ $key ] : array();
            $clean[ $key ] = array(
                'subject'         => sanitize_text_field( wp_unslash( $in['subject'] ?? '' ) ),
                'heading'         => sanitize_text_field( wp_unslash( $in['heading'] ?? '' ) ),
                'intro'           => wp_kses_post( wp_unslash( $in['intro'] ?? '' ) ),
                'footer'          => sanitize_textarea_field( wp_unslash( $in['footer'] ?? '' ) ),
                'include_answers' => empty( $in['include_answers'] ) ? '0' : '1',
                'header_color'    => sanitize_hex_color( $in['header_color'] ?? '' ) ?: $defaults['header_color'],
                'reply_to'        => empty( $in['reply_to'] ) ? '0' : '1',
            );
            // Un asunto vacio se veria como spam: se vuelve al predeterminado.
            if ( '' === $clean[ $key ]['subject'] ) {
                $clean[ $key ]['subject'] = $defaults['subject'];
            }
        }
        return $clean;
    }

    /**
     * Send email notifications after a form submission.
     */
    public function send_notifications( $form_id, $submission, $elements, $submission_number = 0 ) {
        $settings = get_post_meta( $form_id, '_formularios_settings', true );
        if ( empty( $settings ) ) return;

        $config           = self::get_config( $form_id );
        $vars             = $this->build_vars( $form_id, $submission, $submission_number );
        $attachments      = $this->collect_file_attachments( $submission );
        $respondent_email = $this->find_respondent_email( $submission, $elements );
        $respondent_email = is_email( $respondent_email ) ? $respondent_email : '';

        // 1. Notify admin/custom emails
        $admin_emails = $this->parse_email_list( $settings['notify_admin'] ?? '' );
        if ( ! empty( $admin_emails ) ) {
            $mail     = $config['admin'];
            $reply_to = ( '1' === $mail['reply_to'] ) ? $respondent_email : '';
            $this->send_html_email(
                $admin_emails,
                $this->replace_vars( $mail['subject'], $vars ),
                $this->build_email( $mail, $vars, $submission ),
                $attachments,
                $reply_to
            );
        }

        // 2. Notify respondent (if enabled and there's an email field)
        if ( ! empty( $settings['notify_respondent'] ) && '1' === $settings['notify_respondent'] && $respondent_email ) {
            $mail = $config['respondent'];
            $this->send_html_email(
                array( $respondent_email ),
                $this->replace_vars( $mail['subject'], $vars ),
                $this->build_email( $mail, $vars, $submission ),
                '1' === $mail['include_answers'] ? $attachments : array()
            );
        }
    }

    /* ------------------------------------------------------------------
       Variables / placeholders
    ------------------------------------------------------------------ */

    /**
     * Variables disponibles en asunto, titulo, mensaje y pie.
     */
    private function build_vars( $form_id, $submission, $submission_number ) {
        $vars = array(
            '{formulario}' => get_the_title( $form_id ),
            '{numero}'     => (string) $submission_number,
            '{fecha}'      => current_time( 'd/m/Y H:i' ),
            '{sitio}'      => get_bloginfo( 'name' ),
        );
        foreach ( $submission as $field ) {
            $value = $field['value'] ?? '';
            if ( 'file' === ( $field['type'] ?? '' ) ) {
                $value = implode( ', ', array_map( 'basename', (array) $value ) );
            } elseif ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }
            $vars[ '{campo:' . $field['id'] . '}' ] = (string) $value;
        }
        return $vars;
    }

    private function replace_vars( $text, $vars, $escape = false ) {
        if ( $escape ) {
            $vars = array_map( 'esc_html', $vars );
        }
        $text = strtr( $text, $vars );
        // Campos no respondidos (secciones salteadas) quedan vacios.
        return preg_replace( '/\{campo:[^}]+\}/', '', $text );
    }

    /* ------------------------------------------------------------------
       Vista previa (AJAX)
    ------------------------------------------------------------------ */

    public function ajax_preview() {
        check_ajax_referer( 'formularios_nonce', 'nonce' );

        $form_id = absint( $_POST['form_id'] ?? 0 );
        if ( ! $form_id || ! current_user_can( 'edit_post', $form_id ) ) {
            wp_send_json_error( 'Sin permisos' );
        }

        $which  = ( 'respondent' === ( $_POST['which'] ?? '' ) ) ? 'respondent' : 'admin';
        $config = self::sanitize_config( array( $which => $_POST['config'] ?? array() ) );
        $mail   = $config[ $which ];

        $elements = json_decode( wp_unslash( $_POST['elements'] ?? '' ), true );
        if ( ! is_array( $elements ) ) {
            $elements = get_post_meta( $form_id, '_formularios_elements', true );
        }
        $submission = $this->sample_submission( is_array( $elements ) ? $elements : array() );

        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $vars  = $this->build_vars( $form_id, $submission, 123 );
        if ( '' !== $title ) {
            $vars['{formulario}'] = $title;
        }

        wp_send_json_success( array(
            'subject' => $this->replace_vars( $mail['subject'], $vars ),
            'html'    => $this->build_email( $mail, $vars, $submission ),
        ) );
    }

    /**
     * Respuestas de ejemplo para la vista previa.
     */
    private function sample_submission( $elements ) {
        $samples = array(
            'email'    => 'usuario@ejemplo.com',
            'number'   => '42',
            'date'     => current_time( 'Y-m-d' ),
            'textarea' => "Texto de ejemplo de varias lineas.\nSegunda linea.",
            'consent'  => Formularios_Validation::default_consent_label(),
        );
        $submission = array();
        foreach ( $elements as $el ) {
            if ( 'question' !== ( $el['type'] ?? '' ) ) continue;
            $type = $el['input_type'] ?? 'text';
            if ( 'file' === $type ) {
                $value = array( 'documento-ejemplo.pdf' );
            } elseif ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) && ! empty( $el['options'] ) ) {
                $first = $el['options'][0];
                $value = is_array( $first ) ? ( $first['label'] ?? '' ) : $first;
            } else {
                $value = $samples[ $type ] ?? 'Respuesta de ejemplo';
            }
            $submission[] = array(
                'id'    => sanitize_text_field( $el['id'] ?? '' ),
                'label' => sanitize_text_field( $el['label'] ?? '' ),
                'type'  => $type,
                'value' => $value,
            );
        }
        return $submission;
    }

    /* ------------------------------------------------------------------
       Email body builders
    ------------------------------------------------------------------ */

    /**
     * Build the full HTML email using an email-safe table layout.
     */
    private function build_email( $mail, $vars, $submission ) {
        $heading     = $this->replace_vars( $mail['heading'], $vars );
        $form_title  = $vars['{formulario}'];
        $footer_text = $this->replace_vars( $mail['footer'], $vars );
        $intro       = $this->replace_vars( $mail['intro'], $vars, true );
        $color       = $mail['header_color'];

        $h  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
        $h .= '<body style="margin:0;padding:0;background-color:#f3f4f6;-webkit-font-smoothing:antialiased;">';

        // Outer wrapper table
        $h .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;padding:32px 16px;">';
        $h .= '<tr><td align="center">';

        // Inner container
        $h .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;">';

        // Header
        $h .= '<tr><td style="background-color:' . esc_attr( $color ) . ';padding:32px 28px;">';
        $h .= '<h1 style="color:#ffffff;margin:0 0 6px;font-size:20px;font-weight:700;line-height:1.3;">' . esc_html( $heading ) . '</h1>';
        $h .= '<p style="color:rgba(255,255,255,0.75);margin:0;font-size:14px;font-weight:400;">' . esc_html( $form_title ) . '</p>';
        $h .= '</td></tr>';

        // Mensaje personalizado
        if ( '' !== trim( $intro ) ) {
            $h .= '<tr><td style="padding:24px 28px 4px;font-size:15px;color:#374151;line-height:1.6;">' . wpautop( $intro ) . '</td></tr>';
        }

        if ( '1' === $mail['include_answers'] && ! empty( $submission ) ) {
            // Body — short fields side by side (3 per row), long fields full width
            $h .= '<tr><td style="padding:20px 28px;">';
            $h .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;">';

            $cols = 3;
            $row  = array();
            foreach ( $submission as $field ) {
                if ( $this->is_wide_field( $field ) ) {
                    $h  .= $this->render_field_row( $row, $cols );
                    $row = array();
                    $h  .= '<tr><td colspan="' . $cols . '" style="padding:8px 6px;border-bottom:1px solid #f3f4f6;vertical-align:top;">' . $this->render_field( $field ) . '</td></tr>';
                    continue;
                }
                $row[] = $field;
                if ( count( $row ) === $cols ) {
                    $h  .= $this->render_field_row( $row, $cols );
                    $row = array();
                }
            }
            $h .= $this->render_field_row( $row, $cols );

            $h .= '</table>';
            $h .= '</td></tr>';
        }

        // Footer
        if ( '' !== trim( $footer_text ) ) {
            $h .= '<tr><td style="background-color:#f9fafb;padding:20px 28px;border-top:1px solid #e5e7eb;">';
            $h .= '<p style="font-size:12px;color:#9ca3af;margin:0;line-height:1.5;">' . nl2br( esc_html( $footer_text ) ) . '</p>';
            $h .= '</td></tr>';
        }

        // Close containers
        $h .= '</table>';
        $h .= '</td></tr></table>';
        $h .= '</body></html>';

        return $h;
    }

    /**
     * Whether a field needs the full row width (long text, files, lists).
     */
    private function is_wide_field( $field ) {
        $type = $field['type'] ?? '';
        if ( in_array( $type, array( 'file', 'textarea' ), true ) ) {
            return true;
        }
        $value = is_array( $field['value'] ) ? implode( ', ', $field['value'] ) : (string) $field['value'];
        $label = (string) ( $field['label'] ?: $field['id'] );
        return mb_strlen( $value ) > 30 || mb_strlen( $label ) > 40 || false !== strpos( $value, "\n" );
    }

    /**
     * Render a table row with up to $cols short fields side by side.
     */
    private function render_field_row( $fields, $cols ) {
        if ( empty( $fields ) ) return '';

        $width = floor( 100 / $cols );
        $h     = '<tr>';
        foreach ( $fields as $field ) {
            $h .= '<td width="' . $width . '%" style="padding:8px 6px;border-bottom:1px solid #f3f4f6;vertical-align:top;">' . $this->render_field( $field ) . '</td>';
        }
        $remaining = $cols - count( $fields );
        if ( $remaining > 0 ) {
            $h .= '<td colspan="' . $remaining . '" style="border-bottom:1px solid #f3f4f6;">&nbsp;</td>';
        }
        $h .= '</tr>';

        return $h;
    }

    /**
     * Render a single label/value block.
     */
    private function render_field( $field ) {
        $label = esc_html( $field['label'] ?: $field['id'] );
        $h     = '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#9ca3af;margin-bottom:3px;line-height:1.3;">' . $label . '</div>';

        if ( 'file' === ( $field['type'] ?? '' ) ) {
            return $h . $this->render_file_value( $field['value'] );
        }

        $value = $field['value'];
        if ( is_array( $value ) ) {
            $value = implode( ', ', $value );
        }
        if ( '' === $value ) {
            $value = "\xE2\x80\x94";
        }
        $h .= '<div style="font-size:14px;color:#1f2937;line-height:1.4;font-weight:500;word-wrap:break-word;overflow-wrap:break-word;word-break:break-word;">' . nl2br( esc_html( $value ) ) . '</div>';

        return $h;
    }

    /**
     * Render file field value in the email body.
     */
    private function render_file_value( $value ) {
        if ( empty( $value ) ) {
            return '<div style="font-size:15px;color:#9ca3af;font-style:italic;">' . "\xE2\x80\x94" . '</div>';
        }

        $urls = is_array( $value ) ? $value : array( $value );
        $html = '';

        foreach ( $urls as $url ) {
            $filename = basename( $url );
            $path     = $this->url_to_path( $url );
            $attached = ! empty( $path );

            $html .= '<table cellpadding="0" cellspacing="0" border="0" style="margin-bottom:6px;"><tr>';
            $html .= '<td style="padding:8px 12px;background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;line-height:1.4;">';

            if ( $attached ) {
                $html .= '<span style="color:#4F46E5;font-weight:600;">&#128206; ' . esc_html( $filename ) . '</span>';
                $html .= ' <span style="color:#9ca3af;font-size:11px;">(adjunto)</span>';
            } else {
                $html .= '<a href="' . esc_url( $url ) . '" style="color:#4F46E5;text-decoration:none;font-weight:600;" target="_blank">&#128206; ' . esc_html( $filename ) . '</a>';
            }

            $html .= '</td></tr></table>';
        }

        return $html;
    }

    /* ------------------------------------------------------------------
       File attachments
    ------------------------------------------------------------------ */

    /**
     * Collect local file paths from submission data for wp_mail attachments.
     */
    private function collect_file_attachments( $submission ) {
        $attachments = array();

        foreach ( $submission as $field ) {
            if ( 'file' !== ( $field['type'] ?? '' ) || empty( $field['value'] ) ) {
                continue;
            }

            $urls = is_array( $field['value'] ) ? $field['value'] : array( $field['value'] );
            foreach ( $urls as $url ) {
                $path = $this->url_to_path( $url );
                if ( $path ) {
                    $attachments[] = $path;
                }
            }
        }

        return $attachments;
    }

    /**
     * Convert a WordPress upload URL to its local file path.
     */
    private function url_to_path( $url ) {
        if ( empty( $url ) ) return '';

        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        if ( 0 === strpos( $url, $base_url ) ) {
            $path = str_replace( $base_url, $base_dir, $url );
            if ( file_exists( $path ) ) {
                return $path;
            }
        }

        return '';
    }

    /* ------------------------------------------------------------------
       Helpers
    ------------------------------------------------------------------ */

    /**
     * Find the respondent's email from submission data.
     */
    private function find_respondent_email( $submission, $elements ) {
        $email_ids = array();
        foreach ( $elements as $el ) {
            if ( 'question' === $el['type'] && 'email' === ( $el['input_type'] ?? '' ) ) {
                $email_ids[] = $el['id'];
            }
        }

        foreach ( $submission as $field ) {
            if ( in_array( $field['id'], $email_ids, true ) && ! empty( $field['value'] ) ) {
                return $field['value'];
            }
        }

        return '';
    }

    /**
     * Parse a newline-separated list of emails.
     */
    private function parse_email_list( $text ) {
        if ( empty( $text ) ) return array();

        $lines  = preg_split( '/[\r\n,]+/', $text );
        $emails = array();
        foreach ( $lines as $line ) {
            $email = trim( $line );
            if ( is_email( $email ) ) {
                $emails[] = $email;
            }
        }
        return $emails;
    }

    /**
     * Send an HTML email using wp_mail with optional attachments.
     *
     * Wrapped in try-catch to prevent fatal errors from third-party
     * mailer plugins (e.g. WP Mail SMTP) from killing the request.
     */
    private function send_html_email( $to, $subject, $body, $attachments = array(), $reply_to = '' ) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );
        if ( $reply_to ) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        $site_name   = get_bloginfo( 'name' );
        $admin_email = get_option( 'admin_email' );
        if ( $site_name && $admin_email ) {
            $headers[] = 'From: ' . $site_name . ' <' . $admin_email . '>';
        }

        try {
            wp_mail( $to, $subject, $body, $headers, $attachments );
        } catch ( \Throwable $e ) {
            error_log( 'Formularios: wp_mail() failed — ' . $e->getMessage() );
        }
    }
}
