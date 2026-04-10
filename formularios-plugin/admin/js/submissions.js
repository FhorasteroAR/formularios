(function($) {
    'use strict';

    var $panel = $('#fm-detail-panel');
    var $backdrop = $('#fm-detail-backdrop');
    var $body = $('#fm-detail-body');
    var $meta = $('#fm-detail-meta');
    var $num = $('#fm-detail-num');

    // Open detail on entry card click
    $(document).on('click', '.fm-entry', function(e) {
        if ($(e.target).closest('a').length) return;

        var detail = $(this).data('detail');
        if (!detail) return;

        $('.fm-entry').removeClass('fm-entry-active');
        $(this).addClass('fm-entry-active');

        renderDetail(detail);
        openPanel();
    });

    // Close panel
    $(document).on('click', '#fm-detail-close, .fm-detail-backdrop', function() {
        closePanel();
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $panel.is(':visible')) {
            closePanel();
        }
    });

    // --- Export Dropdown ---

    $(document).on('click', '.fm-export-trigger', function(e) {
        e.stopPropagation();
        var $dropdown = $(this).closest('.fm-export-dropdown');
        var wasOpen = $dropdown.hasClass('open');
        $('.fm-export-dropdown').removeClass('open');
        if (!wasOpen) $dropdown.addClass('open');
    });

    $(document).on('click', function() {
        $('.fm-export-dropdown').removeClass('open');
    });

    $(document).on('click', '.fm-export-menu', function(e) {
        e.stopPropagation();
    });

    // Export CSV
    $(document).on('click', '.fm-sub-export-csv', function() {
        $('.fm-export-dropdown').removeClass('open');
        var formId = $(this).data('form-id');
        if (!formId || typeof fmSubmissions === 'undefined') return;

        window.location.href = fmSubmissions.ajax_url +
            '?action=formularios_export_submissions' +
            '&form_id=' + formId +
            '&nonce=' + fmSubmissions.nonce;
    });

    // Export PDF
    $(document).on('click', '.fm-sub-export-pdf', function() {
        $('.fm-export-dropdown').removeClass('open');
        var formId = $(this).data('form-id');
        if (!formId || typeof fmSubmissions === 'undefined') return;

        window.open(
            fmSubmissions.ajax_url +
            '?action=formularios_export_submissions_pdf' +
            '&form_id=' + formId +
            '&nonce=' + fmSubmissions.nonce,
            '_blank'
        );
    });

    // --- Panel ---

    function openPanel() {
        $backdrop.fadeIn(200);
        $panel.addClass('fm-detail-open').css('display', 'flex').hide().fadeIn(250);
    }

    function closePanel() {
        $panel.fadeOut(200, function() {
            $panel.removeClass('fm-detail-open');
        });
        $backdrop.fadeOut(200);
        $('.fm-entry').removeClass('fm-entry-active');
    }

    function renderDetail(data) {
        $num.text('#' + data.num);
        $body.empty();

        if (data.fields && data.fields.length) {
            for (var i = 0; i < data.fields.length; i++) {
                var f = data.fields[i];
                var $field = $('<div class="fm-detail-field">');
                $field.append('<div class="fm-detail-label">' + esc(f.label) + '</div>');

                if (f.type === 'file' && f.value) {
                    var urls = Array.isArray(f.value) ? f.value : [f.value];
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
                    if (Array.isArray(f.value)) {
                        display = f.value.join(', ');
                    } else {
                        display = f.value || '\u2014';
                    }
                    $field.append('<div class="fm-detail-value">' + esc(display) + '</div>');
                }

                $body.append($field);
            }
        }

        // Meta
        var metaHtml = '';
        metaHtml += '<div class="fm-detail-meta-item"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> <strong>' + esc(data.date) + '</strong></div>';
        metaHtml += '<div class="fm-detail-meta-item"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg> <strong>' + esc(data.ip) + '</strong></div>';
        if (data.user_agent) {
            metaHtml += '<div class="fm-detail-meta-item fm-detail-meta-ua" title="' + esc(data.user_agent) + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> <strong>' + esc(data.user_agent.substring(0, 80)) + (data.user_agent.length > 80 ? '...' : '') + '</strong></div>';
        }
        $meta.html(metaHtml);
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
