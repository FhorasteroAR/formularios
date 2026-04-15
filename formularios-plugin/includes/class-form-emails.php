<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_Emails {

    public function __construct() {
        add_action( 'formularios_after_submission', array( $this, 'send_notifications' ), 10, 3 );
    }

    /**
     * Send email notifications after a form submission.
     *
     * @param int   $form_id    The form post ID.
     * @param array $submission The submitted data.
     * @param array $elements   The form element definitions.
     */
    public function send_notifications( $form_id, $submission, $elements ) {
        $settings = get_post_meta( $form_id, '_formularios_settings', true );
        if ( empty( $settings ) ) return;

        $form_title = get_the_title( $form_id );
        $body_html  = $this->build_email_body( $form_title, $submission );

        // 1. Notify admin/custom emails
        $admin_emails = $this->parse_email_list( $settings['notify_admin'] ?? '' );
        if ( ! empty( $admin_emails ) ) {
            $subject = sprintf( 'Nueva respuesta: %s', $form_title );
            $this->send_html_email( $admin_emails, $subject, $body_html );
        }

        // 2. Notify respondent (if enabled and there's an email field)
        if ( ! empty( $settings['notify_respondent'] ) && '1' === $settings['notify_respondent'] ) {
            $respondent_email = $this->find_respondent_email( $submission, $elements );
            if ( $respondent_email && is_email( $respondent_email ) ) {
                $subject = sprintf( 'Copia de tu respuesta: %s', $form_title );
                $respondent_body = $this->build_respondent_email_body( $form_title, $submission );
                $this->send_html_email( array( $respondent_email ), $subject, $respondent_body );
            }
        }
    }

    /**
     * Build the HTML email body for admin notifications.
     */
    private function build_email_body( $form_title, $submission ) {
        $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:600px;margin:0 auto;padding:20px;">';
        $html .= '<div style="background:#f9fafb;border-radius:12px;padding:32px;border:1px solid #e5e7eb;">';
        $html .= '<h2 style="color:#1f2937;margin:0 0 8px;">Nueva respuesta recibida</h2>';
        $html .= '<p style="color:#6b7280;margin:0 0 24px;font-size:14px;">Formulario: <strong>' . esc_html( $form_title ) . '</strong></p>';

        $html .= '<table style="width:100%;border-collapse:collapse;">';
        foreach ( $submission as $field ) {
            $value = $field['value'];
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }
            if ( '' === $value ) {
                $value = '—';
            }
            $html .= '<tr style="border-bottom:1px solid #e5e7eb;">';
            $html .= '<td style="padding:12px 8px;font-weight:600;color:#374151;vertical-align:top;width:40%;">' . esc_html( $field['label'] ?: $field['id'] ) . '</td>';
            $html .= '<td style="padding:12px 8px;color:#1f2937;">' . esc_html( $value ) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        $html .= '<p style="color:#9ca3af;font-size:12px;margin:24px 0 0;">Enviado el ' . esc_html( current_time( 'd/m/Y H:i' ) ) . '</p>';
        $html .= '</div>';
        $html .= '</body></html>';

        return $html;
    }

    /**
     * Build the HTML email body for respondent copy.
     */
    private function build_respondent_email_body( $form_title, $submission ) {
        $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:600px;margin:0 auto;padding:20px;">';
        $html .= '<div style="background:#f9fafb;border-radius:12px;padding:32px;border:1px solid #e5e7eb;">';
        $html .= '<h2 style="color:#1f2937;margin:0 0 8px;">Copia de tu respuesta</h2>';
        $html .= '<p style="color:#6b7280;margin:0 0 24px;font-size:14px;">Formulario: <strong>' . esc_html( $form_title ) . '</strong></p>';

        $html .= '<table style="width:100%;border-collapse:collapse;">';
        foreach ( $submission as $field ) {
            $value = $field['value'];
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }
            if ( '' === $value ) {
                $value = '—';
            }
            $html .= '<tr style="border-bottom:1px solid #e5e7eb;">';
            $html .= '<td style="padding:12px 8px;font-weight:600;color:#374151;vertical-align:top;width:40%;">' . esc_html( $field['label'] ?: $field['id'] ) . '</td>';
            $html .= '<td style="padding:12px 8px;color:#1f2937;">' . esc_html( $value ) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        $html .= '<p style="color:#9ca3af;font-size:12px;margin:24px 0 0;">Gracias por completar el formulario. Esta es una copia de tus respuestas.</p>';
        $html .= '</div>';
        $html .= '</body></html>';

        return $html;
    }

    /**
     * Find the respondent's email from submission data.
     * Looks for the first email-type field with a value.
     */
    private function find_respondent_email( $submission, $elements ) {
        // Build lookup of element IDs to types
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

        $lines = preg_split( '/[\r\n,]+/', $text );
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
     * Send an HTML email using wp_mail.
     *
     * Wrapped in try-catch to prevent fatal errors from third-party
     * mailer plugins (e.g. WP Mail SMTP) from killing the request.
     */
    private function send_html_email( $to, $subject, $body ) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        $site_name = get_bloginfo( 'name' );
        $admin_email = get_option( 'admin_email' );
        if ( $site_name && $admin_email ) {
            $headers[] = 'From: ' . $site_name . ' <' . $admin_email . '>';
        }

        try {
            wp_mail( $to, $subject, $body, $headers );
        } catch ( \Throwable $e ) {
            error_log( 'Formularios: wp_mail() failed — ' . $e->getMessage() );
        }
    }
}
