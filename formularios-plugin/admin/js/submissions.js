(function($) {
    'use strict';

    var $panel = $('#fm-detail-panel');
    var $backdrop = $('#fm-detail-backdrop');
    var $body = $('#fm-detail-body');
    var $meta = $('#fm-detail-meta');
    var $num = $('#fm-detail-num');

    // Open detail on row click
    $(document).on('click', '.fm-submission-row', function(e) {
        // Don't open if user clicked a link inside the row
        if ($(e.target).closest('a').length) return;

        var detail = $(this).data('detail');
        if (!detail) return;

        // Highlight active row
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

    function openPanel() {
        $backdrop.fadeIn(200);
        $panel.css('display', 'flex').hide().fadeIn(250);
    }

    function closePanel() {
        $panel.fadeOut(200);
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
                        display = f.value || '—';
                    }
                    $field.append('<div class="fm-detail-value">' + esc(display) + '</div>');
                }

                $body.append($field);
            }
        }

        // Meta
        var metaHtml = '<span>Fecha: <strong>' + esc(data.date) + '</strong></span>';
        metaHtml += '<span>IP: <strong>' + esc(data.ip) + '</strong></span>';
        if (data.user_agent) {
            metaHtml += '<span title="' + esc(data.user_agent) + '">Navegador: <strong>' + esc(data.user_agent.substring(0, 60)) + (data.user_agent.length > 60 ? '...' : '') + '</strong></span>';
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
