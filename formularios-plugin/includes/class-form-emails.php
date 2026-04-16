<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_Emails {

    public function __construct() {
        add_action( 'formularios_after_submission', array( $this, 'send_notifications' ), 10, 4 );
    }

    /**
     * Send email notifications after a form submission.
     */
    public function send_notifications( $form_id, $submission, $elements, $submission_number = 0 ) {
        $settings = get_post_meta( $form_id, '_formularios_settings', true );
        if ( empty( $settings ) ) return;

        $form_title  = get_the_title( $form_id );
        $attachments = $this->collect_file_attachments( $submission );

        // 1. Notify admin/custom emails
        $admin_emails = $this->parse_email_list( $settings['notify_admin'] ?? '' );
        if ( ! empty( $admin_emails ) ) {
            $subject   = sprintf( 'Nueva respuesta #%d: %s', $submission_number, $form_title );
            $body_html = $this->build_admin_email( $form_title, $submission, $submission_number );
            $this->send_html_email( $admin_emails, $subject, $body_html, $attachments );
        }

        // 2. Notify respondent (if enabled and there's an email field)
        if ( ! empty( $settings['notify_respondent'] ) && '1' === $settings['notify_respondent'] ) {
            $respondent_email = $this->find_respondent_email( $submission, $elements );
            if ( $respondent_email && is_email( $respondent_email ) ) {
                $subject         = sprintf( 'Copia de tu respuesta #%d: %s', $submission_number, $form_title );
                $respondent_body = $this->build_respondent_email( $form_title, $submission, $submission_number );
                $this->send_html_email( array( $respondent_email ), $subject, $respondent_body, $attachments );
            }
        }
    }

    /* ------------------------------------------------------------------
       Email body builders
    ------------------------------------------------------------------ */

    private function build_admin_email( $form_title, $submission, $submission_number ) {
        return $this->build_email(
            sprintf( 'Nueva respuesta recibida (#%d)', $submission_number ),
            $form_title,
            $submission,
            sprintf( 'Respuesta #%d — Enviado el %s', $submission_number, esc_html( current_time( 'd/m/Y H:i' ) ) )
        );
    }

    private function build_respondent_email( $form_title, $submission, $submission_number ) {
        return $this->build_email(
            sprintf( 'Copia de tu respuesta (#%d)', $submission_number ),
            $form_title,
            $submission,
            sprintf( 'Respuesta #%d — Gracias por completar el formulario. Esta es una copia de tus respuestas.', $submission_number )
        );
    }

    /**
     * Build the full HTML email using an email-safe table layout.
     */
    private function build_email( $heading, $form_title, $submission, $footer_text ) {
        $h  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
        $h .= '<body style="margin:0;padding:0;background-color:#f3f4f6;-webkit-font-smoothing:antialiased;">';

        // Outer wrapper table
        $h .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;padding:32px 16px;">';
        $h .= '<tr><td align="center">';

        // Inner container
        $h .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;">';

        // Header
        $h .= '<tr><td style="background-color:#4F46E5;padding:32px 28px;">';
        $h .= '<h1 style="color:#ffffff;margin:0 0 6px;font-size:20px;font-weight:700;line-height:1.3;">' . esc_html( $heading ) . '</h1>';
        $h .= '<p style="color:rgba(255,255,255,0.75);margin:0;font-size:14px;font-weight:400;">' . esc_html( $form_title ) . '</p>';
        $h .= '</td></tr>';

        // Body — stacked label/value fields
        $h .= '<tr><td style="padding:28px;">';

        $total  = count( $submission );
        $idx    = 0;
        foreach ( $submission as $field ) {
            $idx++;
            $label = esc_html( $field['label'] ?: $field['id'] );
            $is_file = ( 'file' === ( $field['type'] ?? '' ) );
            $is_last = ( $idx === $total );

            // Field wrapper
            $border = $is_last ? '' : 'border-bottom:1px solid #f3f4f6;';
            $h .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="' . $border . 'margin-bottom:' . ( $is_last ? '0' : '16px' ) . ';padding-bottom:' . ( $is_last ? '0' : '16px' ) . ';">';
            $h .= '<tr><td>';

            // Label
            $h .= '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#9ca3af;margin-bottom:6px;line-height:1.4;">' . $label . '</div>';

            // Value
            if ( $is_file ) {
                $h .= $this->render_file_value( $field['value'] );
            } else {
                $value = $field['value'];
                if ( is_array( $value ) ) {
                    $value = implode( ', ', $value );
                }
                if ( '' === $value ) {
                    $value = "\xE2\x80\x94";
                }
                $h .= '<div style="font-size:15px;color:#1f2937;line-height:1.6;font-weight:500;word-wrap:break-word;overflow-wrap:break-word;word-break:break-word;">' . nl2br( esc_html( $value ) ) . '</div>';
            }

            $h .= '</td></tr></table>';
        }

        $h .= '</td></tr>';

        // Footer
        $h .= '<tr><td style="background-color:#f9fafb;padding:20px 28px;border-top:1px solid #e5e7eb;">';
        $h .= '<p style="font-size:12px;color:#9ca3af;margin:0;line-height:1.5;">' . esc_html( $footer_text ) . '</p>';
        $h .= '</td></tr>';

        // Close containers
        $h .= '</table>';
        $h .= '</td></tr></table>';
        $h .= '</body></html>';

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
    private function send_html_email( $to, $subject, $body, $attachments = array() ) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

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
