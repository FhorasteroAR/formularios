<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_Submissions_Page {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_formularios_export_submissions', array( $this, 'ajax_export_csv' ) );
        add_action( 'wp_ajax_formularios_export_submissions_pdf', array( $this, 'ajax_export_pdf' ) );
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

    private function get_form_headers( $form_id ) {
        $elements = get_post_meta( $form_id, '_formularios_elements', true );
        $headers  = array();
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
        return $headers;
    }

    public function enqueue_assets( $hook ) {
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

        // Determine active form
        $active_form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
        if ( ! $active_form_id ) {
            $first = get_posts( array(
                'post_type'      => 'formulario',
                'posts_per_page' => 1,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'orderby'        => 'title',
                'order'          => 'ASC',
            ) );
            if ( ! empty( $first ) ) {
                $active_form_id = $first[0]->ID;
            }
        }

        $headers = array();
        $rows    = array();

        if ( $active_form_id ) {
            $headers = $this->get_form_headers( $active_form_id );

            global $wpdb;
            $table       = $wpdb->prefix . 'formularios_submissions';
            $submissions = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC",
                $active_form_id
            ) );

            foreach ( $submissions as $row ) {
                $data     = json_decode( $row->data, true );
                $data_map = array();
                if ( is_array( $data ) ) {
                    foreach ( $data as $field ) {
                        $data_map[ $field['id'] ] = $field;
                    }
                }

                $fields = array();
                foreach ( $headers as $h ) {
                    $raw = isset( $data_map[ $h['id'] ] ) ? $data_map[ $h['id'] ]['value'] : '';
                    $fields[ $h['id'] ] = $raw;
                }

                $rows[] = array(
                    'id'         => (int) $row->id,
                    'fields'     => (object) $fields,
                    'date'       => $row->submitted_at,
                    'ip'         => $row->ip_address,
                    'user_agent' => $row->user_agent ?? '',
                );
            }
        }

        wp_localize_script( 'formularios-submissions-page', 'fmSubmissions', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'formularios_export_submissions' ),
            'rows'     => $rows,
            'headers'  => $headers,
            'form_id'  => $active_form_id,
        ) );
    }

    /* ------------------------------------------------------------------
       AJAX: Export CSV
    ------------------------------------------------------------------ */
    public function ajax_export_csv() {
        check_ajax_referer( 'formularios_export_submissions', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Unauthorized' );

        $form_id = absint( $_GET['form_id'] ?? 0 );
        if ( ! $form_id ) wp_die( 'Invalid form' );

        global $wpdb;
        $table = $wpdb->prefix . 'formularios_submissions';

        $form       = get_post( $form_id );
        $form_title = $form ? sanitize_file_name( $form->post_title ?: 'formulario-' . $form_id ) : 'formulario-' . $form_id;
        $headers    = $this->get_form_headers( $form_id );

        // Optional ID filter
        $ids = array();
        if ( ! empty( $_GET['ids'] ) ) {
            $ids = array_map( 'absint', explode( ',', sanitize_text_field( $_GET['ids'] ) ) );
            $ids = array_filter( $ids );
        }

        if ( ! empty( $ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $submissions  = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE form_id = %d AND id IN ($placeholders) ORDER BY submitted_at DESC",
                array_merge( array( $form_id ), $ids )
            ) );
        } else {
            $submissions = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC",
                $form_id
            ) );
        }

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="respuestas-' . $form_title . '.csv"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        $csv_headers = array( '#' );
        foreach ( $headers as $h ) {
            $csv_headers[] = $h['label'];
        }
        $csv_headers[] = 'Fecha';
        $csv_headers[] = 'IP';
        fputcsv( $output, $csv_headers );

        foreach ( $submissions as $idx => $row ) {
            $data     = json_decode( $row->data, true );
            $data_map = array();
            if ( is_array( $data ) ) {
                foreach ( $data as $field ) {
                    $data_map[ $field['id'] ] = $field;
                }
            }

            $csv_row = array( $idx + 1 );
            foreach ( $headers as $h ) {
                $raw_val = isset( $data_map[ $h['id'] ] ) ? $data_map[ $h['id'] ]['value'] : '';
                $csv_row[] = is_array( $raw_val ) ? implode( ', ', $raw_val ) : $raw_val;
            }
            $csv_row[] = $row->submitted_at;
            $csv_row[] = $row->ip_address;
            fputcsv( $output, $csv_row );
        }

        fclose( $output );
        exit;
    }

    /* ------------------------------------------------------------------
       AJAX: Export PDF (print-ready HTML)
    ------------------------------------------------------------------ */
    public function ajax_export_pdf() {
        check_ajax_referer( 'formularios_export_submissions', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Unauthorized' );

        $form_id = absint( $_GET['form_id'] ?? 0 );
        if ( ! $form_id ) wp_die( 'Invalid form' );

        global $wpdb;
        $table = $wpdb->prefix . 'formularios_submissions';

        $form       = get_post( $form_id );
        $form_title = $form ? ( $form->post_title ?: 'Formulario #' . $form_id ) : 'Formulario #' . $form_id;
        $headers    = $this->get_form_headers( $form_id );

        // Optional ID filter
        $ids = array();
        if ( ! empty( $_GET['ids'] ) ) {
            $ids = array_map( 'absint', explode( ',', sanitize_text_field( $_GET['ids'] ) ) );
            $ids = array_filter( $ids );
        }

        if ( ! empty( $ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $submissions  = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE form_id = %d AND id IN ($placeholders) ORDER BY submitted_at DESC",
                array_merge( array( $form_id ), $ids )
            ) );
        } else {
            $submissions = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC",
                $form_id
            ) );
        }

        header( 'Content-Type: text/html; charset=utf-8' );

        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<title>Respuestas — ' . esc_html( $form_title ) . '</title>';
        echo '<style>';
        echo 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1F2937;max-width:900px;margin:0 auto;padding:40px 32px;font-size:13px;line-height:1.5}';
        echo 'h1{font-size:20px;font-weight:700;margin:0 0 4px}';
        echo '.subtitle{font-size:12px;color:#6B7280;margin-bottom:24px}';
        echo '.entry{border:1px solid #E5E7EB;border-radius:8px;padding:16px;margin-bottom:12px;page-break-inside:avoid}';
        echo '.entry-header{display:flex;align-items:center;gap:12px;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #F3F4F6;font-size:12px;color:#6B7280}';
        echo '.entry-num{font-weight:700;color:#4F46E5;font-size:13px}';
        echo '.entry-fields{display:grid;grid-template-columns:repeat(2,1fr);gap:8px 16px}';
        echo '.field-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9CA3AF}';
        echo '.field-value{font-size:13px;color:#1F2937;font-weight:500;word-break:break-word}';
        echo '@media print{body{padding:20px}.entry{break-inside:avoid}}';
        echo '</style></head><body>';
        echo '<h1>' . esc_html( $form_title ) . '</h1>';
        echo '<div class="subtitle">' . esc_html( count( $submissions ) ) . ' respuestas</div>';

        foreach ( $submissions as $idx => $row ) {
            $data     = json_decode( $row->data, true );
            $data_map = array();
            if ( is_array( $data ) ) {
                foreach ( $data as $field ) {
                    $data_map[ $field['id'] ] = $field;
                }
            }

            $ts       = strtotime( $row->submitted_at );
            $date_str = $ts ? gmdate( 'd/m/Y H:i', $ts ) : $row->submitted_at;

            echo '<div class="entry">';
            echo '<div class="entry-header">';
            echo '<span class="entry-num">#' . ( $idx + 1 ) . '</span>';
            echo '<span>' . esc_html( $date_str ) . '</span>';
            echo '<span>' . esc_html( $row->ip_address ) . '</span>';
            echo '</div>';
            echo '<div class="entry-fields">';

            foreach ( $headers as $h ) {
                $raw_val = isset( $data_map[ $h['id'] ] ) ? $data_map[ $h['id'] ]['value'] : '';
                $val     = is_array( $raw_val ) ? implode( ', ', $raw_val ) : $raw_val;
                if ( '' === $val ) $val = "\xE2\x80\x94";

                echo '<div><div class="field-label">' . esc_html( $h['label'] ) . '</div>';
                echo '<div class="field-value">' . esc_html( $val ) . '</div></div>';
            }

            echo '</div></div>';
        }

        echo '<script>window.onload=function(){window.print()}</script>';
        echo '</body></html>';
        exit;
    }

    /* ------------------------------------------------------------------
       Render Page
    ------------------------------------------------------------------ */
    public function render_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'formularios_submissions';

        $forms = get_posts( array(
            'post_type'      => 'formulario',
            'posts_per_page' => -1,
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        $active_form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
        if ( ! $active_form_id && ! empty( $forms ) ) {
            $active_form_id = $forms[0]->ID;
        }

        // Submission counts per form
        $counts       = array();
        $count_results = $wpdb->get_results( "SELECT form_id, COUNT(*) as cnt FROM {$table} GROUP BY form_id" );
        foreach ( $count_results as $cr ) {
            $counts[ (int) $cr->form_id ] = (int) $cr->cnt;
        }

        // Quick stats
        $active_total = $counts[ $active_form_id ] ?? 0;
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

                <!-- Tabs -->
                <div class="fm-submissions-tabs">
                    <?php foreach ( $forms as $form ) :
                        $count     = $counts[ $form->ID ] ?? 0;
                        $is_active = ( $active_form_id === $form->ID );
                        $tab_url   = admin_url( 'edit.php?post_type=formulario&page=formularios-submissions&form_id=' . $form->ID );
                    ?>
                        <a href="<?php echo esc_url( $tab_url ); ?>"
                           class="fm-tab <?php echo $is_active ? 'fm-tab-active' : ''; ?>">
                            <span class="fm-tab-title"><?php echo esc_html( $form->post_title ?: 'Sin titulo #' . $form->ID ); ?></span>
                            <span class="fm-tab-count"><?php echo esc_html( $count ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Summary Stats -->
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
                </div>

                <?php if ( $active_total === 0 ) : ?>
                    <div class="fm-submissions-empty">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <p>Este formulario todavia no tiene respuestas.</p>
                    </div>
                <?php else : ?>

                    <!-- Toolbar -->
                    <div class="fm-toolbar">
                        <div class="fm-toolbar-left">
                            <div class="fm-search-wrap">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <input type="text" id="fm-search" class="fm-search-input" placeholder="Buscar respuestas..." />
                            </div>
                            <select id="fm-date-filter" class="fm-filter-select">
                                <option value="">Todas las fechas</option>
                                <option value="today">Hoy</option>
                                <option value="7d">&Uacute;ltimos 7 d&iacute;as</option>
                                <option value="30d">&Uacute;ltimos 30 d&iacute;as</option>
                                <option value="90d">&Uacute;ltimos 90 d&iacute;as</option>
                            </select>
                        </div>
                        <div class="fm-toolbar-right">
                            <div class="fm-export-dropdown">
                                <button type="button" class="fm-export-trigger">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    Exportar
                                    <svg class="fm-export-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                                <div class="fm-export-menu">
                                    <button type="button" class="fm-export-option" id="fm-export-csv-all">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                        <span class="fm-export-option-text"><strong>CSV &mdash; Todas</strong><small>Hoja de c&aacute;lculo</small></span>
                                    </button>
                                    <button type="button" class="fm-export-option" id="fm-export-pdf-all">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15v-2a1 1 0 0 1 1-1h1a1 1 0 0 1 0 2H9"/></svg>
                                        <span class="fm-export-option-text"><strong>PDF &mdash; Todas</strong><small>Documento</small></span>
                                    </button>
                                    <button type="button" class="fm-export-option fm-export-selected-only" id="fm-export-csv-sel" style="display:none">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="9 11 12 14 22 4"/></svg>
                                        <span class="fm-export-option-text"><strong>CSV &mdash; Seleccionadas</strong><small>Solo marcadas</small></span>
                                    </button>
                                    <button type="button" class="fm-export-option fm-export-selected-only" id="fm-export-pdf-sel" style="display:none">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="9 11 12 14 22 4"/></svg>
                                        <span class="fm-export-option-text"><strong>PDF &mdash; Seleccionadas</strong><small>Solo marcadas</small></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Selection Bar -->
                    <div id="fm-selection-bar" class="fm-selection-bar" style="display:none">
                        <span class="fm-sel-info">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                            <strong id="fm-sel-count">0</strong> seleccionadas
                        </span>
                        <div class="fm-sel-actions">
                            <button type="button" id="fm-sel-csv" class="fm-sel-action">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                CSV
                            </button>
                            <button type="button" id="fm-sel-pdf" class="fm-sel-action">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                PDF
                            </button>
                            <button type="button" id="fm-sel-clear" class="fm-sel-action fm-sel-clear">Deseleccionar</button>
                        </div>
                    </div>

                    <!-- Data Table -->
                    <div class="fm-datatable-wrap">
                        <table class="fm-datatable">
                            <thead><tr id="fm-thead-row"></tr></thead>
                            <tbody id="fm-tbody"></tbody>
                        </table>
                    </div>

                    <!-- Table Footer -->
                    <div class="fm-table-footer">
                        <div class="fm-table-info" id="fm-table-info"></div>
                        <div class="fm-table-pager" id="fm-table-pager"></div>
                        <div class="fm-page-size-wrap">
                            <select id="fm-page-size" class="fm-filter-select">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                            <span>por p&aacute;gina</span>
                        </div>
                    </div>

                    <!-- Detail Panel -->
                    <div id="fm-detail-backdrop" class="fm-detail-backdrop" style="display:none"></div>
                    <div id="fm-detail-panel" class="fm-detail-panel" style="display:none">
                        <div class="fm-detail-header">
                            <h2 class="fm-detail-title">Respuesta <span id="fm-detail-num"></span></h2>
                            <div class="fm-detail-header-actions">
                                <button type="button" id="fm-detail-pdf" class="fm-detail-action" title="Exportar PDF">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    PDF
                                </button>
                                <button type="button" id="fm-detail-close" class="fm-detail-close">&times;</button>
                            </div>
                        </div>
                        <div id="fm-detail-body" class="fm-detail-body"></div>
                        <div class="fm-detail-meta" id="fm-detail-meta"></div>
                    </div>

                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }
}
