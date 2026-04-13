(function($) {
    'use strict';

    if (typeof fmSubmissions === 'undefined') return;

    var headers   = fmSubmissions.headers || [];
    var allRows   = fmSubmissions.rows || [];
    var formId    = fmSubmissions.form_id;
    var ajaxUrl   = fmSubmissions.ajax_url;
    var nonce     = fmSubmissions.nonce;

    var filtered  = [];
    var page      = 1;
    var pageSize  = 25;
    var selected  = {};
    var detailRow = null;

    // --- Init ---

    $(document).ready(function() {
        if (!headers.length) return;
        buildHeader();
        applyFilters();
    });

    // --- Build Table Header ---

    function buildHeader() {
        var $row = $('#fm-thead-row');
        $row.empty();

        $row.append('<th class="fm-th-check"><input type="checkbox" id="fm-check-all" /></th>');
        $row.append('<th class="fm-th-num">#</th>');

        var visibleCount = Math.min(headers.length, 4);
        for (var i = 0; i < visibleCount; i++) {
            $row.append('<th>' + esc(headers[i].label) + '</th>');
        }

        $row.append('<th>Fecha</th>');
        $row.append('<th class="fm-th-ip">IP</th>');
        $row.append('<th class="fm-th-arrow"></th>');
    }

    // --- Filtering ---

    function applyFilters() {
        var search = ($('#fm-search').val() || '').toLowerCase().trim();
        var dateFilter = $('#fm-date-filter').val();

        var now = new Date();
        var cutoff = null;

        if (dateFilter === 'today') {
            cutoff = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        } else if (dateFilter === '7d') {
            cutoff = new Date(now.getTime() - 7 * 86400000);
        } else if (dateFilter === '30d') {
            cutoff = new Date(now.getTime() - 30 * 86400000);
        } else if (dateFilter === '90d') {
            cutoff = new Date(now.getTime() - 90 * 86400000);
        }

        filtered = [];

        for (var r = 0; r < allRows.length; r++) {
            var row = allRows[r];

            // Date filter
            if (cutoff) {
                var rowDate = new Date(row.date);
                if (rowDate < cutoff) continue;
            }

            // Search filter
            if (search) {
                var match = false;
                var fields = row.fields || {};
                for (var key in fields) {
                    if (!fields.hasOwnProperty(key)) continue;
                    var val = fields[key];
                    var str = Array.isArray(val) ? val.join(', ') : (val || '');
                    if (str.toLowerCase().indexOf(search) !== -1) {
                        match = true;
                        break;
                    }
                }
                if (!match && (row.ip || '').toLowerCase().indexOf(search) !== -1) match = true;
                if (!match && (row.date || '').toLowerCase().indexOf(search) !== -1) match = true;
                if (!match) continue;
            }

            filtered.push(row);
        }

        page = 1;
        renderTable();
    }

    // --- Render Table ---

    function renderTable() {
        var $tbody = $('#fm-tbody');
        $tbody.empty();

        var totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        if (page > totalPages) page = totalPages;

        var start = (page - 1) * pageSize;
        var end   = Math.min(start + pageSize, filtered.length);
        var visibleCount = Math.min(headers.length, 4);

        if (filtered.length === 0) {
            var colSpan = visibleCount + 5;
            $tbody.append(
                '<tr><td colspan="' + colSpan + '" class="fm-table-empty">' +
                '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
                '<span>No se encontraron resultados</span>' +
                '</td></tr>'
            );
        } else {
            for (var i = start; i < end; i++) {
                var row = filtered[i];
                var globalIdx = allRows.indexOf(row) + 1;
                var isChecked = !!selected[row.id];

                var $tr = $('<tr class="fm-row' + (isChecked ? ' fm-row-selected' : '') + '" data-id="' + row.id + '" data-index="' + i + '">');

                // Checkbox
                $tr.append('<td class="fm-td-check"><input type="checkbox" class="fm-row-check" value="' + row.id + '"' + (isChecked ? ' checked' : '') + ' /></td>');

                // Number
                $tr.append('<td class="fm-td-num">' + globalIdx + '</td>');

                // Field columns
                for (var c = 0; c < visibleCount; c++) {
                    var hdr = headers[c];
                    var raw = row.fields ? row.fields[hdr.id] : '';
                    var display = '';
                    if (hdr.type === 'file' && raw) {
                        var urls = Array.isArray(raw) ? raw : [raw];
                        display = urls.length + ' archivo' + (urls.length > 1 ? 's' : '');
                    } else if (Array.isArray(raw)) {
                        display = raw.join(', ');
                    } else {
                        display = raw || '\u2014';
                    }
                    $tr.append('<td><span class="fm-cell-text">' + esc(display) + '</span></td>');
                }

                // Date
                var ts = new Date(row.date);
                var dateStr = isNaN(ts.getTime()) ? row.date : formatDate(ts);
                $tr.append('<td class="fm-td-date">' + esc(dateStr) + '</td>');

                // IP
                $tr.append('<td class="fm-td-ip">' + esc(row.ip || '') + '</td>');

                // Arrow
                $tr.append('<td class="fm-td-arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></td>');

                $tbody.append($tr);
            }
        }

        renderPager(totalPages);
        renderInfo(start, end);
        updateSelectionUI();
        updateCheckAll();
    }

    // --- Pagination ---

    function renderPager(totalPages) {
        var $pager = $('#fm-table-pager');
        $pager.empty();

        if (totalPages <= 1) return;

        if (page > 1) {
            $pager.append('<button class="fm-page-btn fm-page-prev" data-page="' + (page - 1) + '">&lsaquo;</button>');
        }

        var pages = getPageRange(page, totalPages);
        for (var i = 0; i < pages.length; i++) {
            var p = pages[i];
            if (p === '...') {
                $pager.append('<span class="fm-page-ellipsis">&hellip;</span>');
            } else {
                var cls = p === page ? ' fm-page-active' : '';
                $pager.append('<button class="fm-page-btn' + cls + '" data-page="' + p + '">' + p + '</button>');
            }
        }

        if (page < totalPages) {
            $pager.append('<button class="fm-page-btn fm-page-next" data-page="' + (page + 1) + '">&rsaquo;</button>');
        }
    }

    function getPageRange(current, total) {
        if (total <= 7) {
            var arr = [];
            for (var i = 1; i <= total; i++) arr.push(i);
            return arr;
        }
        var pages = [1];
        if (current > 3) pages.push('...');
        var start = Math.max(2, current - 1);
        var end   = Math.min(total - 1, current + 1);
        for (var j = start; j <= end; j++) pages.push(j);
        if (current < total - 2) pages.push('...');
        pages.push(total);
        return pages;
    }

    function renderInfo(start, end) {
        var $info = $('#fm-table-info');
        if (filtered.length === 0) {
            $info.text('0 resultados');
        } else {
            $info.text((start + 1) + '\u2013' + end + ' de ' + filtered.length + ' respuestas');
        }
    }

    // --- Selection ---

    function updateSelectionUI() {
        var count = Object.keys(selected).length;
        var $bar = $('#fm-selection-bar');
        var $selOptions = $('.fm-export-selected-only');

        if (count > 0) {
            $bar.slideDown(200);
            $selOptions.show();
        } else {
            $bar.slideUp(200);
            $selOptions.hide();
        }
        $('#fm-sel-count').text(count);
    }

    function updateCheckAll() {
        var $checks = $('.fm-row-check');
        var $checkAll = $('#fm-check-all');
        if (!$checks.length) {
            $checkAll.prop('checked', false).prop('indeterminate', false);
            return;
        }
        var total   = $checks.length;
        var checked = $checks.filter(':checked').length;
        $checkAll.prop('checked', checked === total);
        $checkAll.prop('indeterminate', checked > 0 && checked < total);
    }

    // --- Events: Search & Filter ---

    var searchTimeout;
    $(document).on('input', '#fm-search', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(applyFilters, 250);
    });

    $(document).on('change', '#fm-date-filter', applyFilters);

    $(document).on('change', '#fm-page-size', function() {
        pageSize = parseInt($(this).val(), 10) || 25;
        page = 1;
        renderTable();
    });

    // --- Events: Pagination ---

    $(document).on('click', '.fm-page-btn', function() {
        var p = parseInt($(this).data('page'), 10);
        if (p && p !== page) {
            page = p;
            renderTable();
            $('.fm-datatable-wrap')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    // --- Events: Selection ---

    $(document).on('change', '#fm-check-all', function() {
        var isChecked = $(this).prop('checked');
        $('.fm-row-check').each(function() {
            var id = parseInt($(this).val(), 10);
            $(this).prop('checked', isChecked);
            $(this).closest('.fm-row').toggleClass('fm-row-selected', isChecked);
            if (isChecked) {
                selected[id] = true;
            } else {
                delete selected[id];
            }
        });
        updateSelectionUI();
    });

    $(document).on('change', '.fm-row-check', function(e) {
        e.stopPropagation();
        var id = parseInt($(this).val(), 10);
        var isChecked = $(this).prop('checked');
        $(this).closest('.fm-row').toggleClass('fm-row-selected', isChecked);
        if (isChecked) {
            selected[id] = true;
        } else {
            delete selected[id];
        }
        updateCheckAll();
        updateSelectionUI();
    });

    $(document).on('click', '.fm-td-check', function(e) {
        e.stopPropagation();
    });

    $(document).on('click', '#fm-sel-clear', function() {
        selected = {};
        $('.fm-row-check').prop('checked', false);
        $('.fm-row').removeClass('fm-row-selected');
        $('#fm-check-all').prop('checked', false).prop('indeterminate', false);
        updateSelectionUI();
    });

    // --- Events: Row click -> Detail Panel ---

    $(document).on('click', '.fm-row', function(e) {
        if ($(e.target).closest('.fm-td-check').length) return;
        if ($(e.target).is('input[type="checkbox"]')) return;

        var id = parseInt($(this).data('id'), 10);
        var row = null;
        for (var i = 0; i < allRows.length; i++) {
            if (allRows[i].id === id) { row = allRows[i]; break; }
        }
        if (!row) return;

        detailRow = row;

        $('.fm-row').removeClass('fm-row-active');
        $(this).addClass('fm-row-active');

        renderDetail(row, allRows.indexOf(row) + 1);
        openPanel();
    });

    // --- Detail Panel ---

    function openPanel() {
        $('#fm-detail-backdrop').fadeIn(200);
        $('#fm-detail-panel').addClass('fm-detail-open').css('display', 'flex').hide().fadeIn(250);
    }

    function closePanel() {
        $('#fm-detail-panel').fadeOut(200, function() {
            $(this).removeClass('fm-detail-open');
        });
        $('#fm-detail-backdrop').fadeOut(200);
        $('.fm-row').removeClass('fm-row-active');
        detailRow = null;
    }

    function renderDetail(row, num) {
        $('#fm-detail-num').text('#' + num);
        var $body = $('#fm-detail-body').empty();

        for (var i = 0; i < headers.length; i++) {
            var h   = headers[i];
            var raw = row.fields ? row.fields[h.id] : '';

            var $field = $('<div class="fm-detail-field">');
            $field.append('<div class="fm-detail-label">' + esc(h.label) + '</div>');

            if (h.type === 'file' && raw) {
                var urls = Array.isArray(raw) ? raw : [raw];
                var $val = $('<div class="fm-detail-value">');
                for (var j = 0; j < urls.length; j++) {
                    var fname = urls[j].split('/').pop();
                    $val.append(
                        '<a href="' + esc(urls[j]) + '" target="_blank" rel="noopener" class="fm-detail-file-link">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> ' +
                        esc(fname) + '</a> '
                    );
                }
                $field.append($val);
            } else {
                var display = '';
                if (Array.isArray(raw)) {
                    display = raw.join(', ');
                } else {
                    display = raw || '\u2014';
                }
                $field.append('<div class="fm-detail-value">' + esc(display) + '</div>');
            }

            $body.append($field);
        }

        // Meta
        var metaHtml = '';
        metaHtml += '<div class="fm-detail-meta-item"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> <strong>' + esc(row.date) + '</strong></div>';
        metaHtml += '<div class="fm-detail-meta-item"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg> <strong>' + esc(row.ip) + '</strong></div>';
        if (row.user_agent) {
            metaHtml += '<div class="fm-detail-meta-item fm-detail-meta-ua" title="' + esc(row.user_agent) + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> <strong>' + esc(row.user_agent.substring(0, 80)) + (row.user_agent.length > 80 ? '...' : '') + '</strong></div>';
        }
        $('#fm-detail-meta').html(metaHtml);
    }

    // --- Close Panel ---

    $(document).on('click', '#fm-detail-close, .fm-detail-backdrop', closePanel);
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#fm-detail-panel').is(':visible')) closePanel();
    });

    // --- Export Dropdown ---

    $(document).on('click', '.fm-export-trigger', function(e) {
        e.stopPropagation();
        var $dd = $(this).closest('.fm-export-dropdown');
        var wasOpen = $dd.hasClass('open');
        $('.fm-export-dropdown').removeClass('open');
        if (!wasOpen) $dd.addClass('open');
    });

    $(document).on('click', function() {
        $('.fm-export-dropdown').removeClass('open');
    });

    $(document).on('click', '.fm-export-menu', function(e) {
        e.stopPropagation();
    });

    // --- Export Actions ---

    function buildExportUrl(action, ids) {
        var url = ajaxUrl + '?action=' + action + '&form_id=' + formId + '&nonce=' + nonce;
        if (ids && ids.length) {
            url += '&ids=' + ids.join(',');
        }
        return url;
    }

    function getSelectedIds() {
        return Object.keys(selected).map(function(k) { return parseInt(k, 10); });
    }

    // All CSV
    $(document).on('click', '#fm-export-csv-all', function() {
        $('.fm-export-dropdown').removeClass('open');
        window.location.href = buildExportUrl('formularios_export_submissions');
    });

    // All PDF
    $(document).on('click', '#fm-export-pdf-all', function() {
        $('.fm-export-dropdown').removeClass('open');
        window.open(buildExportUrl('formularios_export_submissions_pdf'), '_blank');
    });

    // Selected CSV
    $(document).on('click', '#fm-export-csv-sel, #fm-sel-csv', function() {
        $('.fm-export-dropdown').removeClass('open');
        var ids = getSelectedIds();
        if (!ids.length) return;
        window.location.href = buildExportUrl('formularios_export_submissions', ids);
    });

    // Selected PDF
    $(document).on('click', '#fm-export-pdf-sel, #fm-sel-pdf', function() {
        $('.fm-export-dropdown').removeClass('open');
        var ids = getSelectedIds();
        if (!ids.length) return;
        window.open(buildExportUrl('formularios_export_submissions_pdf', ids), '_blank');
    });

    // Single PDF from detail panel
    $(document).on('click', '#fm-detail-pdf', function() {
        if (!detailRow) return;
        window.open(buildExportUrl('formularios_export_submissions_pdf', [detailRow.id]), '_blank');
    });

    // --- Helpers ---

    function formatDate(d) {
        var dd = ('0' + d.getDate()).slice(-2);
        var mm = ('0' + (d.getMonth() + 1)).slice(-2);
        var yy = d.getFullYear();
        var hh = ('0' + d.getHours()).slice(-2);
        var mi = ('0' + d.getMinutes()).slice(-2);
        return dd + '/' + mm + '/' + yy + ' ' + hh + ':' + mi;
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
