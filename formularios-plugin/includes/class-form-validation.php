<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reglas de validacion compartidas entre el renderer y el handler de envios.
 *
 * El renderer las usa para emitir restricciones HTML (min, max, maxlength,
 * inputmode) y el handler para volver a aplicarlas en el servidor, ya que
 * cualquier restriccion de HTML o JS se puede saltear.
 */
class Formularios_Validation {

    const FORMATS    = array( 'any', 'letters', 'numbers', 'alphanumeric' );
    const DATE_MODES = array( 'none', 'today', 'custom' );

    /**
     * Texto que se guarda como respuesta cuando se acepta un consentimiento.
     * La leyenda completa de los terminos nunca se guarda ni se envia: en la
     * respuesta y en el email solo aparece esta constancia de aceptacion.
     */
    public static function default_consent_label() {
        return 'He leído y acepto la declaración de privacidad.';
    }

    /**
     * Tipos de pregunta que aceptan reglas de formato y longitud.
     */
    public static function supports_format( $input_type ) {
        return in_array( $input_type, array( 'text', 'textarea' ), true );
    }

    /**
     * Formato de caracteres configurado para un elemento.
     */
    public static function get_format( $el ) {
        $format = $el['text_format'] ?? 'any';
        return in_array( $format, self::FORMATS, true ) ? $format : 'any';
    }

    /**
     * Expresion regular (PCRE) que debe cumplir el valor completo.
     */
    public static function format_pattern( $format ) {
        switch ( $format ) {
            case 'letters':
                return '/^[\p{L}\s\'\.\-]+$/u';
            case 'numbers':
                return '/^[0-9]+$/';
            case 'alphanumeric':
                return '/^[\p{L}0-9\s\'\.\-]+$/u';
        }
        return '';
    }

    /**
     * Mensaje por defecto cuando el valor no respeta el formato.
     */
    public static function format_error_default( $format ) {
        switch ( $format ) {
            case 'letters':
                return 'Este campo solo admite letras.';
            case 'numbers':
                return 'Este campo solo admite numeros.';
            case 'alphanumeric':
                return 'Este campo solo admite letras y numeros.';
        }
        return 'El valor ingresado no es valido.';
    }

    /**
     * Resuelve un limite de fecha a formato Y-m-d, o '' si no hay limite.
     *
     * @param string $mode   none | today | custom
     * @param string $custom Fecha Y-m-d cuando $mode es custom.
     */
    public static function resolve_date_bound( $mode, $custom ) {
        if ( 'today' === $mode ) {
            return current_time( 'Y-m-d' );
        }
        if ( 'custom' === $mode && self::is_ymd( (string) $custom ) ) {
            return (string) $custom;
        }
        return '';
    }

    /**
     * Valida un valor ya sanitizado contra las reglas del elemento.
     *
     * @return string Mensaje de error, o '' si el valor es valido.
     */
    public static function validate( $el, $value ) {
        if ( ! is_string( $value ) ) return '';
        $value = trim( $value );
        if ( '' === $value ) return '';

        switch ( $el['input_type'] ?? 'text' ) {
            case 'date':
                return self::validate_date( $el, $value );
            case 'number':
                return self::validate_number( $el, $value );
            case 'text':
            case 'textarea':
                return self::validate_text( $el, $value );
        }
        return '';
    }

    private static function validate_text( $el, $value ) {
        $format  = self::get_format( $el );
        $pattern = self::format_pattern( $format );

        if ( $pattern && ! preg_match( $pattern, $value ) ) {
            return self::message( $el, 'custom_format_error', self::format_error_default( $format ) );
        }

        return self::validate_length( $el, self::strlen( $value ), 'caracteres', 'custom_format_error' );
    }

    private static function validate_number( $el, $value ) {
        $number_error = $el['custom_number_error'] ?? '';

        if ( ! empty( $el['integer_only'] ) ) {
            if ( ! preg_match( '/^-?[0-9]+$/', $value ) ) {
                return '' !== $number_error ? $number_error : 'Ingresa un numero entero, sin letras ni simbolos.';
            }
        } elseif ( ! is_numeric( $value ) ) {
            return '' !== $number_error ? $number_error : 'Ingresa un numero valido.';
        }

        $min = self::num_or_empty( $el['min_value'] ?? '' );
        $max = self::num_or_empty( $el['max_value'] ?? '' );

        if ( '' !== $min && (float) $value < (float) $min ) {
            return '' !== $number_error ? $number_error : sprintf( 'El valor minimo permitido es %s.', $min );
        }
        if ( '' !== $max && (float) $value > (float) $max ) {
            return '' !== $number_error ? $number_error : sprintf( 'El valor maximo permitido es %s.', $max );
        }

        // La longitud se mide en digitos, ignorando signo y separador decimal.
        $digits = preg_replace( '/[^0-9]/', '', $value );
        return self::validate_length( $el, strlen( $digits ), 'digitos', 'custom_number_error' );
    }

    private static function validate_length( $el, $length, $unit, $msg_key ) {
        $min = self::int_or_empty( $el['min_length'] ?? '' );
        $max = self::int_or_empty( $el['max_length'] ?? '' );

        if ( '' !== $min && $length < $min ) {
            return self::message( $el, $msg_key, sprintf( 'Debe tener al menos %d %s.', $min, $unit ) );
        }
        if ( '' !== $max && $length > $max ) {
            return self::message( $el, $msg_key, sprintf( 'No puede superar los %d %s.', $max, $unit ) );
        }
        return '';
    }

    private static function validate_date( $el, $value ) {
        $custom = $el['custom_date_error'] ?? '';

        if ( ! self::is_ymd( $value ) ) {
            return '' !== $custom ? $custom : 'Ingresa una fecha valida.';
        }

        list( $y, $m, $d ) = array_map( 'intval', explode( '-', $value ) );
        if ( ! checkdate( $m, $d, $y ) ) {
            return '' !== $custom ? $custom : 'Ingresa una fecha valida.';
        }

        $min = self::resolve_date_bound( $el['date_min_mode'] ?? 'none', $el['date_min_custom'] ?? '' );
        $max = self::resolve_date_bound( $el['date_max_mode'] ?? 'none', $el['date_max_custom'] ?? '' );

        if ( '' !== $min && $value < $min ) {
            return '' !== $custom
                ? $custom
                : sprintf( 'La fecha no puede ser anterior al %s.', self::display_date( $min ) );
        }
        if ( '' !== $max && $value > $max ) {
            $is_today = ( $max === current_time( 'Y-m-d' ) );
            $default  = $is_today
                ? 'No se pueden seleccionar fechas futuras.'
                : sprintf( 'La fecha no puede ser posterior al %s.', self::display_date( $max ) );
            return '' !== $custom ? $custom : $default;
        }

        return '';
    }

    private static function message( $el, $key, $default ) {
        $custom = $el[ $key ] ?? '';
        return '' !== $custom ? $custom : $default;
    }

    private static function display_date( $ymd ) {
        return date_i18n( 'd/m/Y', strtotime( $ymd . ' 00:00:00' ) );
    }

    public static function is_ymd( $value ) {
        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
    }

    public static function int_or_empty( $value ) {
        return ( '' === $value || null === $value ) ? '' : absint( $value );
    }

    public static function num_or_empty( $value ) {
        return is_numeric( $value ) ? $value + 0 : '';
    }

    private static function strlen( $value ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
    }
}
