<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Codigo de seguimiento de cada respuesta: PREFIJO-AAAA-NNNNNN
 *
 * Ejemplo: ROG-2026-000123
 *
 * No se guarda en la base de datos: se calcula a partir del ID interno de la
 * respuesta, el ano de envio y el prefijo configurado en el formulario. Por eso
 * las respuestas ya existentes tambien tienen codigo, sin migrar nada.
 */
class Formularios_Tracking {

    const DEFAULT_PREFIX = 'FRM';

    /**
     * Normaliza un prefijo: solo letras (sin acentos), mayusculas, 2 a 5 caracteres.
     */
    public static function sanitize_prefix( $prefix ) {
        $prefix = strtoupper( preg_replace( '/[^A-Za-z]/', '', remove_accents( (string) $prefix ) ) );
        $prefix = substr( $prefix, 0, 5 );
        return strlen( $prefix ) >= 2 ? $prefix : '';
    }

    /**
     * Prefijo configurado para un formulario (o el predeterminado).
     */
    public static function get_prefix( $form_id ) {
        $settings = get_post_meta( $form_id, '_formularios_settings', true );
        $prefix   = is_array( $settings ) ? self::sanitize_prefix( $settings['code_prefix'] ?? '' ) : '';
        return '' !== $prefix ? $prefix : self::DEFAULT_PREFIX;
    }

    /**
     * Arma el codigo de una respuesta.
     *
     * @param int         $form_id       ID del formulario.
     * @param int         $submission_id ID de la respuesta (tabla formularios_submissions).
     * @param string|null $submitted_at  Fecha de envio ('Y-m-d H:i:s'); si falta, se usa el ano actual.
     */
    public static function format( $form_id, $submission_id, $submitted_at = null ) {
        $ts   = $submitted_at ? strtotime( $submitted_at ) : false;
        $year = $ts ? gmdate( 'Y', $ts ) : current_time( 'Y' );
        return sprintf( '%s-%s-%06d', self::get_prefix( $form_id ), $year, absint( $submission_id ) );
    }

    /**
     * Codigo de una respuesta ya guardada, tomando el ano de su fecha real de envio.
     */
    public static function for_submission_id( $submission_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'formularios_submissions';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, form_id, submitted_at FROM {$table} WHERE id = %d", absint( $submission_id ) ) );
        return $row ? self::format( (int) $row->form_id, (int) $row->id, $row->submitted_at ) : '';
    }

    /**
     * Lee un codigo escrito por una persona. Devuelve el ID de la respuesta o 0
     * si el formato no es valido. Tolera minusculas, espacios y guiones largos.
     */
    public static function parse( $code ) {
        $code = strtoupper( trim( (string) $code ) );
        $code = preg_replace( '/[\x{2010}-\x{2015}\x{2212}]/u', '-', $code );
        $code = preg_replace( '/\s+/', '', $code );
        if ( preg_match( '/^([A-Z]{2,5})-(\d{4})-(\d{1,9})$/', $code, $m ) ) {
            return array(
                'prefix' => $m[1],
                'year'   => $m[2],
                'id'     => (int) $m[3],
            );
        }
        return array();
    }

    /**
     * Busca una respuesta por su codigo completo. Devuelve la fila o null.
     * Se exige que el codigo coincida exactamente con el que se generaria hoy,
     * para no confundir respuestas de distintos formularios.
     */
    public static function find_submission( $code ) {
        $parsed = self::parse( $code );
        if ( empty( $parsed['id'] ) ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'formularios_submissions';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $parsed['id'] ) );
        if ( ! $row ) {
            return null;
        }

        $expected = self::format( (int) $row->form_id, (int) $row->id, $row->submitted_at );
        $given    = sprintf( '%s-%s-%06d', $parsed['prefix'], $parsed['year'], $parsed['id'] );
        return ( $expected === $given ) ? $row : null;
    }
}
