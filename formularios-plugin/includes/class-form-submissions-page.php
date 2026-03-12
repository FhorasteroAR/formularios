<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_Submissions_Page {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
    }

    public function add_menu_page() {
        add_submenu_page(
            'edit.php?post_type=formulario',
            'Respuestas',
            'Respuestas',
            'edit_posts',
            'formularios-submissions',
            array( $this, 'render_page' )
        );
    }

    public function enqueue_styles( $hook ) {
        if ( 'formulario_page_formularios-submissions' !== $hook ) return;

        wp_enqueue_style(
            'formularios-submissions-page',
            FORMULARIOS_URL . 'admin/css/submissions.css',
            array(),
            FORMULARIOS_VERSION
        );
    }

    public function render_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'formularios_submissions';

        // Get all forms that have submissions
        $forms = get_posts( array(
            'post_type'      => 'formulario',
            'posts_per_page' => -1,
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        // Determine active tab
        $active_form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

        // If no form selected, pick the first one
        if ( ! $active_form_id && ! empty( $forms ) ) {
            $active_form_id = $forms[0]->ID;
        }

        // Pagination
        $per_page = 20;
        $current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $offset = ( $current_page - 1 ) * $per_page;

        // Get total count for active form
        $total_items = 0;
        if ( $active_form_id ) {
            $total_items = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE form_id = %d",
                $active_form_id
            ) );
        }
        $total_pages = ceil( $total_items / $per_page );

        // Get submissions for active form
        $submissions = array();
        if ( $active_form_id ) {
            $submissions = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
                $active_form_id, $per_page, $offset
            ) );
        }

        // Get submission counts per form
        $counts = array();
        $count_results = $wpdb->get_results(
            "SELECT form_id, COUNT(*) as cnt FROM {$table} GROUP BY form_id"
        );
        foreach ( $count_results as $cr ) {
            $counts[ (int) $cr->form_id ] = (int) $cr->cnt;
        }

        ?>
        <div class="wrap fm-submissions-wrap">
            <h1 class="wp-heading-inline">Respuestas de formularios</h1>

            <?php if ( empty( $forms ) ) : ?>
                <div class="fm-submissions-empty">
                    <p>No hay formularios creados todavia.</p>
                </div>
            <?php else : ?>

                <div class="fm-submissions-tabs">
                    <?php foreach ( $forms as $form ) :
                        $count = $counts[ $form->ID ] ?? 0;
                        $is_active = ( $active_form_id === $form->ID );
                        $tab_url = admin_url( 'edit.php?post_type=formulario&page=formularios-submissions&form_id=' . $form->ID );
                    ?>
                        <a href="<?php echo esc_url( $tab_url ); ?>"
                           class="fm-tab <?php echo $is_active ? 'fm-tab-active' : ''; ?>">
                            <span class="fm-tab-title"><?php echo esc_html( $form->post_title ?: 'Sin titulo #' . $form->ID ); ?></span>
                            <span class="fm-tab-count"><?php echo esc_html( $count ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ( empty( $submissions ) ) : ?>
                    <div class="fm-submissions-empty">
                        <p>Este formulario todavia no tiene respuestas.</p>
                    </div>
                <?php else : ?>

                    <?php
                    // Build column headers from form elements
                    $elements = get_post_meta( $active_form_id, '_formularios_elements', true );
                    $headers = array();
                    if ( is_array( $elements ) ) {
                        foreach ( $elements as $el ) {
                            if ( 'question' === $el['type'] ) {
                                $headers[] = array(
                                    'id'    => $el['id'],
                                    'label' => $el['label'] ?: $el['id'],
                                    'type'  => $el['input_type'] ?? 'text',
                                );
                            }
                        }
                    }
                    ?>

                    <div class="fm-submissions-table-wrap">
                        <table class="wp-list-table widefat fixed striped fm-submissions-table">
                            <thead>
                                <tr>
                                    <th class="fm-col-id">#</th>
                                    <?php foreach ( $headers as $h ) : ?>
                                        <th><?php echo esc_html( $h['label'] ); ?></th>
                                    <?php endforeach; ?>
                                    <th class="fm-col-date">Fecha</th>
                                    <th class="fm-col-ip">IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $submissions as $idx => $row ) :
                                    $data = json_decode( $row->data, true );
                                    $data_map = array();
                                    if ( is_array( $data ) ) {
                                        foreach ( $data as $field ) {
                                            $data_map[ $field['id'] ] = $field;
                                        }
                                    }
                                    $row_num = $offset + $idx + 1;
                                ?>
                                    <tr>
                                        <td class="fm-col-id"><?php echo esc_html( $row_num ); ?></td>
                                        <?php foreach ( $headers as $h ) :
                                            $val = '';
                                            if ( isset( $data_map[ $h['id'] ] ) ) {
                                                $val = $data_map[ $h['id'] ]['value'];
                                                if ( is_array( $val ) ) $val = implode( ', ', $val );
                                            }
                                            // For file uploads, show a link
                                            if ( 'file' === $h['type'] && ! empty( $val ) ) : ?>
                                                <td><a href="<?php echo esc_url( $val ); ?>" target="_blank" rel="noopener">Ver archivo</a></td>
                                            <?php else : ?>
                                                <td><?php echo esc_html( $val ); ?></td>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <td class="fm-col-date"><?php echo esc_html( $row->submitted_at ); ?></td>
                                        <td class="fm-col-ip"><?php echo esc_html( $row->ip_address ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ( $total_pages > 1 ) : ?>
                        <div class="fm-pagination">
                            <?php
                            $base_url = admin_url( 'edit.php?post_type=formulario&page=formularios-submissions&form_id=' . $active_form_id );
                            for ( $p = 1; $p <= $total_pages; $p++ ) :
                                $page_url = add_query_arg( 'paged', $p, $base_url );
                                $is_current = ( $p === $current_page );
                            ?>
                                <?php if ( $is_current ) : ?>
                                    <span class="fm-page-num fm-page-current"><?php echo esc_html( $p ); ?></span>
                                <?php else : ?>
                                    <a href="<?php echo esc_url( $page_url ); ?>" class="fm-page-num"><?php echo esc_html( $p ); ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <span class="fm-page-info">
                                <?php echo esc_html( sprintf( '%d respuestas en total', $total_items ) ); ?>
                            </span>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }
}
