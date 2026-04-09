(function($) {
    'use strict';

    var $panel = $('#fm-detail-panel');
    var $backdrop = $('#fm-detail-backdrop');
    var $body = $('#fm-detail-body');
    var $meta = $('#fm-detail-meta');
    var $num = $('#fm-detail-num');

    // Open detail on row click
    $(document).on('click', '.fm-submission-row', function(e) {
        if ($(e.target).closest('a').length) return;

        var detail = $(this).data('detail');
        if (!detail) return;

        $('.fm-submission-row').removeClass('fm-row-active');
        $(this).addClass('fm-row-active');

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

    // Export CSV
    $(document).on('click', '.fm-sub-export-btn', function() {
        var formId = $(this).data('form-id');
        if (!formId || typeof fmSubmissions === 'undefined') return;

        var url = fmSubmissions.ajax_url +
            '?action=formularios_export_submissions' +
            '&form_id=' + formId +
            '&nonce=' + fmSubmissions.nonce;

        window.location.href = url;
    });

    function openPanel() {
        $backdrop.fadeIn(200);
        $panel.addClass('fm-detail-open').css('display', 'flex').hide().fadeIn(250);
    }

    function closePanel() {
        $panel.fadeOut(200, function() {
            $panel.removeClass('fm-detail-open');
        });
        $backdrop.fadeOut(200);
        $('.fm-submission-row').removeClass('fm-row-active');
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
