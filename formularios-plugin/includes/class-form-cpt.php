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
            'name'               => __( 'Forms', 'formularios' ),
            'singular_name'      => __( 'Form', 'formularios' ),
            'add_new'            => __( 'Add New Form', 'formularios' ),
            'add_new_item'       => __( 'Add New Form', 'formularios' ),
            'edit_item'          => __( 'Edit Form', 'formularios' ),
            'new_item'           => __( 'New Form', 'formularios' ),
            'view_item'          => __( 'View Form', 'formularios' ),
            'search_items'       => __( 'Search Forms', 'formularios' ),
            'not_found'          => __( 'No forms found', 'formularios' ),
            'not_found_in_trash' => __( 'No forms found in Trash', 'formularios' ),
            'menu_name'          => __( 'Formularios', 'formularios' ),
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
                $new_columns['shortcode']   = __( 'Shortcode', 'formularios' );
                $new_columns['submissions'] = __( 'Submissions', 'formularios' );
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
