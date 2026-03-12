<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_CPT {

    public function __construct() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_filter( 'manage_formulario_posts_columns', array( $this, 'add_columns' ) );
        add_action( 'manage_formulario_posts_custom_column', array( $this, 'render_columns' ), 10, 2 );
    }

    public function register_post_type() {
        $labels = array(
            'name'               => 'Formularios',
            'singular_name'      => 'Formulario',
            'add_new'            => 'Agregar nuevo',
            'add_new_item'       => 'Agregar nuevo formulario',
            'edit_item'          => 'Editar formulario',
            'new_item'           => 'Nuevo formulario',
            'view_item'          => 'Ver formulario',
            'search_items'       => 'Buscar formularios',
            'not_found'          => 'No se encontraron formularios',
            'not_found_in_trash' => 'No se encontraron formularios en la papelera',
            'menu_name'          => 'Formularios',
        );

        $args = array(
            'labels'       => $labels,
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-feedback',
            'supports'     => array( 'title' ),
            'has_archive'  => false,
            'rewrite'      => false,
        );

        register_post_type( 'formulario', $args );
    }

    public function add_columns( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            if ( 'title' === $key ) {
                $new_columns['shortcode']   = 'Shortcode';
                $new_columns['submissions'] = 'Respuestas';
            }
        }
        return $new_columns;
    }

    public function render_columns( $column, $post_id ) {
        if ( 'shortcode' === $column ) {
            echo '<code>[formulario id="' . esc_attr( $post_id ) . '"]</code>';
        }
        if ( 'submissions' === $column ) {
            global $wpdb;
            $table = $wpdb->prefix . 'formularios_submissions';
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $post_id
            ) );
            echo intval( $count );
        }
    }
}
