<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_Submissions_Page {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'wp_ajax_formularios_export_submissions', array( $this, 'ajax_export_csv' ) );
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

        wp_enqueue_script(
            'formularios-submissions-page',
            FORMULARIOS_URL . 'admin/js/submissions.js',
            array( 'jquery' ),
            FORMULARIOS_VERSION,
            true
        );

        wp_localize_script( 'formularios-submissions-page', 'fmSubmissions', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'formularios_export_submissions' ),
        ) );
    }

    /**
     * AJAX handler to export submissions as CSV.
     */
    public function ajax_export_csv() {
        check_ajax_referer( 'formularios_export_submissions', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Unauthorized' );
        }

        $form_id = absint( $_GET['form_id'] ?? 0 );
        if ( ! $form_id ) {
            wp_die( 'Invalid form' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'formularios_submissions';

        $form = get_post( $form_id );
        $form_title = $form ? sanitize_file_name( $form->post_title ?: 'formulario-' . $form_id ) : 'formulario-' . $form_id;

        // Get form elements for column headers
        $elements = get_post_meta( $form_id, '_formularios_elements', true );
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

        $submissions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC",
            $form_id
        ) );

        // Build CSV
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="respuestas-' . $form_title . '.csv"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        // BOM for Excel UTF-8
        fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        // Header row
        $csv_headers = array( '#' );
        foreach ( $headers as $h ) {
            $csv_headers[] = $h['label'];
        }
        $csv_headers[] = 'Fecha';
        $csv_headers[] = 'IP';
        fputcsv( $output, $csv_headers );

        // Data rows
        foreach ( $submissions as $idx => $row ) {
            $data = json_decode( $row->data, true );
            $data_map = array();
            if ( is_array( $data ) ) {
                foreach ( $data as $field ) {
                    $data_map[ $field['id'] ] = $field;
                }
            }

            $csv_row = array( $idx + 1 );
            foreach ( $headers as $h ) {
                $raw_val = isset( $data_map[ $h['id'] ] ) ? $data_map[ $h['id'] ]['value'] : '';
                if ( is_array( $raw_val ) ) {
                    $csv_row[] = implode( ', ', $raw_val );
                } else {
                    $csv_row[] = $raw_val;
                }
            }
            $csv_row[] = $row->submitted_at;
            $csv_row[] = $row->ip_address;
            fputcsv( $output, $csv_row );
        }

        fclose( $output );
        exit;
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

        // Compute quick stats for active form
        $active_today = 0;
        $active_week  = 0;
        if ( $active_form_id ) {
            $today_str = current_time( 'Y-m-d' );
            $week_str  = gmdate( 'Y-m-d', strtotime( '-7 days', strtotime( $today_str ) ) );
            $active_today = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND DATE(submitted_at) = %s",
                $active_form_id, $today_str
            ) );
            $active_week = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND DATE(submitted_at) >= %s",
                $active_form_id, $week_str
            ) );
        }
        $active_total = $counts[ $active_form_id ] ?? 0;

        ?>
        <div class="wrap fm-submissions-wrap">
            <div class="fm-sub-top-bar">
                <h1 class="wp-heading-inline">Respuestas</h1>
            </div>

            <?php if ( empty( $forms ) ) : ?>
                <div class="fm-submissions-empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
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

                <!-- Summary cards for active form -->
                <div class="fm-sub-stats">
                    <div class="fm-sub-stat-card">
                        <span class="fm-sub-stat-value"><?php echo esc_html( $active_total ); ?></span>
                        <span class="fm-sub-stat-label">Total</span>
                    </div>
                    <div class="fm-sub-stat-card">
                        <span class="fm-sub-stat-value"><?php echo esc_html( $active_today ); ?></span>
                        <span class="fm-sub-stat-label">Hoy</span>
                    </div>
                    <div class="fm-sub-stat-card">
                        <span class="fm-sub-stat-value"><?php echo esc_html( $active_week ); ?></span>
                        <span class="fm-sub-stat-label">Esta semana</span>
                    </div>
                    <div class="fm-sub-stat-spacer"></div>
                    <?php if ( $active_total > 0 ) : ?>
                        <button type="button" class="fm-sub-export-btn" data-form-id="<?php echo esc_attr( $active_form_id ); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Exportar CSV
                        </button>
                    <?php endif; ?>
                </div>

                <?php if ( empty( $submissions ) ) : ?>
                    <div class="fm-submissions-empty">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
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
                        <table class="fm-submissions-table">
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

                                    // Build detail data for the panel
                                    $detail_fields = array();
                                    foreach ( $headers as $h ) {
                                        $raw_val = isset( $data_map[ $h['id'] ] ) ? $data_map[ $h['id'] ]['value'] : '';
                                        $detail_fields[] = array(
                                            'label' => $h['label'],
                                            'type'  => $h['type'],
                                            'value' => $raw_val,
                                        );
                                    }
                                    $detail_json = wp_json_encode( array(
                                        'num'        => $row_num,
                                        'fields'     => $detail_fields,
                                        'date'       => $row->submitted_at,
                                        'ip'         => $row->ip_address,
                                        'user_agent' => $row->user_agent ?? '',
                                    ), JSON_UNESCAPED_UNICODE );

                                    // Format date
                                    $ts = strtotime( $row->submitted_at );
                                    $formatted_date = $ts ? gmdate( 'd/m/Y H:i', $ts ) : $row->submitted_at;
                                ?>
                                    <tr class="fm-submission-row" data-detail="<?php echo esc_attr( $detail_json ); ?>">
                                        <td class="fm-col-id"><?php echo esc_html( $row_num ); ?></td>
                                        <?php foreach ( $headers as $h ) :
                                            $raw_val = isset( $data_map[ $h['id'] ] ) ? $data_map[ $h['id'] ]['value'] : '';
                                            if ( 'file' === $h['type'] && ! empty( $raw_val ) ) :
                                                $file_urls = is_array( $raw_val ) ? $raw_val : array( $raw_val );
                                                ?>
                                                <td>
                                                    <?php foreach ( $file_urls as $fi => $furl ) : ?>
                                                        <a href="<?php echo esc_url( $furl ); ?>" target="_blank" rel="noopener" class="fm-table-file-link">
                                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                                            <?php echo esc_html( sprintf( 'Archivo %d', $fi + 1 ) ); ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </td>
                                            <?php else :
                                                $val = is_array( $raw_val ) ? implode( ', ', $raw_val ) : $raw_val;
                                                ?>
                                                <td title="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $val ); ?></td>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <td class="fm-col-date"><?php echo esc_html( $formatted_date ); ?></td>
                                        <td class="fm-col-ip"><?php echo esc_html( $row->ip_address ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Detail Panel (hidden by default) -->
                    <div id="fm-detail-backdrop" class="fm-detail-backdrop" style="display:none"></div>
                    <div id="fm-detail-panel" class="fm-detail-panel" style="display:none">
                        <div class="fm-detail-header">
                            <h2 class="fm-detail-title">Respuesta <span id="fm-detail-num"></span></h2>
                            <button type="button" id="fm-detail-close" class="fm-detail-close">&times;</button>
                        </div>
                        <div id="fm-detail-body" class="fm-detail-body"></div>
                        <div class="fm-detail-meta" id="fm-detail-meta"></div>
                    </div>

                    <?php if ( $total_pages > 1 ) : ?>
                        <div class="fm-pagination">
                            <?php
                            $base_url = admin_url( 'edit.php?post_type=formulario&page=formularios-submissions&form_id=' . $active_form_id );

                            // Previous
                            if ( $current_page > 1 ) :
                                $prev_url = add_query_arg( 'paged', $current_page - 1, $base_url );
                            ?>
                                <a href="<?php echo esc_url( $prev_url ); ?>" class="fm-page-num fm-page-arrow">&lsaquo;</a>
                            <?php endif; ?>

                            <?php for ( $p = 1; $p <= $total_pages; $p++ ) :
                                $page_url = add_query_arg( 'paged', $p, $base_url );
                                $is_current = ( $p === $current_page );
                            ?>
                                <?php if ( $is_current ) : ?>
                                    <span class="fm-page-num fm-page-current"><?php echo esc_html( $p ); ?></span>
                                <?php else : ?>
                                    <a href="<?php echo esc_url( $page_url ); ?>" class="fm-page-num"><?php echo esc_html( $p ); ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ( $current_page < $total_pages ) :
                                $next_url = add_query_arg( 'paged', $current_page + 1, $base_url );
                            ?>
                                <a href="<?php echo esc_url( $next_url ); ?>" class="fm-page-num fm-page-arrow">&rsaquo;</a>
                            <?php endif; ?>

                            <span class="fm-page-info">
                                <?php echo esc_html( sprintf( 'Pagina %d de %d — %d respuestas', $current_page, $total_pages, $total_items ) ); ?>
                            </span>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }
}
