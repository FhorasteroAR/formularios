(function($) {
    'use strict';

    $(document).ready(function() {
        $('.formularios-form-wrap').each(function() {
            initForm($(this));
        });
    });

    function initForm($wrap) {
        var $form = $wrap.find('.formularios-form');
        var $sections = $form.find('.fm-section');
        var hasSections = $sections.length > 0;
        var currentSection = 1;
        var totalSections = $sections.length;
        var i18n = (typeof formulariosFront !== 'undefined' && formulariosFront.i18n) ? formulariosFront.i18n : {};
        var captchaEnabled   = formulariosFront.captcha_enabled || false;
        var captchaProvider  = formulariosFront.captcha_provider || 'recaptcha';
        var captchaSiteKey   = formulariosFront.captcha_site_key || '';
        var hasFileUpload    = formulariosFront.has_file_upload || false;
        var turnstileWidgetId       = null;
        var turnstilePendingCallback = null;

        // Parse branching data
        var branchingMap = {};
        var branchingData = $wrap.attr('data-branching');
        if (branchingData) {
            try {
                branchingMap = JSON.parse(branchingData);
            } catch(e) {
                branchingMap = {};
            }
        }
        var hasBranching = Object.keys(branchingMap).length > 0;

        // Parse conditional logic data
        var conditionsMap = {};
        var conditionsData = $wrap.attr('data-conditions');
        if (conditionsData) {
            try {
                conditionsMap = JSON.parse(conditionsData);
            } catch(e) {
                conditionsMap = {};
            }
        }
        var hasConditions = Object.keys(conditionsMap).length > 0;

        // Track section visit history for back navigation with branching
        var sectionHistory = [1];

        if (hasSections) {
            if (hasConditions) evaluateConditions();
            updateProgress();
            updateNavButtons();
        }

        if (captchaEnabled && captchaProvider === 'turnstile' && captchaSiteKey) {
            initTurnstileWidget();
        }

        // Next button
        $wrap.on('click', '.fm-btn-next', function() {
            if (!validateSection(currentSection)) return;

            var nextSection = determineNextSection(currentSection);

            if (nextSection === '__end__') {
                $form.trigger('submit');
                return;
            }

            if (nextSection > 0 && nextSection <= totalSections) {
                currentSection = nextSection;
                if (hasBranching || hasConditions) {
                    sectionHistory.push(currentSection);
                }
                showSection(currentSection);
                updateProgress();
                updateNavButtons();
                scrollToTop();
            }
        });

        // Previous button
        $wrap.on('click', '.fm-btn-prev', function() {
            if ((hasBranching || hasConditions) && sectionHistory.length > 1) {
                sectionHistory.pop();
                currentSection = sectionHistory[sectionHistory.length - 1];
            } else {
                var prev = getPrevVisibleSection(currentSection - 1);
                if (prev > 0) currentSection = prev;
            }
            showSection(currentSection);
            updateProgress();
            updateNavButtons();
            scrollToTop();
        });

        // Form submit
        $form.on('submit', function(e) {
            e.preventDefault();

            if (hasSections && !validateSection(currentSection)) return;
            if (!hasSections && !validateAll()) return;

            // Validate file fields client-side
            if (!validateFiles()) return;

            var $submitBtn = $form.find('.fm-btn-submit');
            $submitBtn.prop('disabled', true).text(i18n.sending || 'Enviando...');

            if (captchaEnabled && captchaSiteKey) {
                if (captchaProvider === 'turnstile') {
                    getTurnstileToken(function(token) {
                        submitForm(token);
                    });
                } else {
                    getCaptchaToken(function(token) {
                        submitForm(token);
                    });
                }
            } else {
                submitForm('');
            }
        });

        function getCaptchaToken(callback) {
            // Wait for grecaptcha to be available (script may still be loading)
            if (typeof grecaptcha === 'undefined') {
                var attempts = 0;
                var poll = setInterval(function() {
                    attempts++;
                    if (typeof grecaptcha !== 'undefined') {
                        clearInterval(poll);
                        executeCaptcha(callback);
                    } else if (attempts >= 50) {
                        // After ~5 seconds, give up and submit without token
                        clearInterval(poll);
                        callback('');
                    }
                }, 100);
            } else {
                executeCaptcha(callback);
            }
        }

        function executeCaptcha(callback) {
            try {
                grecaptcha.ready(function() {
                    grecaptcha.execute(captchaSiteKey, { action: 'formularios_submit' }).then(function(token) {
                        callback(token || '');
                    }).catch(function() {
                        callback('');
                    });
                });
            } catch(e) {
                callback('');
            }
        }

        function initTurnstileWidget() {
            var container = document.getElementById('fm-turnstile-' + $wrap.attr('data-form-id'));
            if (!container) return;
            if (typeof turnstile !== 'undefined') {
                renderTurnstileWidget(container);
                return;
            }
            var attempts = 0;
            var poll = setInterval(function() {
                attempts++;
                if (typeof turnstile !== 'undefined') {
                    clearInterval(poll);
                    renderTurnstileWidget(container);
                } else if (attempts >= 100) {
                    clearInterval(poll);
                }
            }, 100);
        }

        function renderTurnstileWidget(container) {
            turnstileWidgetId = turnstile.render(container, {
                sitekey: captchaSiteKey,
                size: 'invisible',
                execution: 'execute',
                callback: function(token) {
                    if (turnstilePendingCallback) {
                        var cb = turnstilePendingCallback;
                        turnstilePendingCallback = null;
                        cb(token || '');
                    }
                },
                'error-callback': function() {
                    if (turnstilePendingCallback) {
                        var cb = turnstilePendingCallback;
                        turnstilePendingCallback = null;
                        cb('');
                    }
                },
                'expired-callback': function() {
                    if (turnstileWidgetId !== null) {
                        try { turnstile.reset(turnstileWidgetId); } catch(e) {}
                    }
                }
            });
        }

        function getTurnstileToken(callback) {
            if (typeof turnstile === 'undefined') {
                var attempts = 0;
                var poll = setInterval(function() {
                    attempts++;
                    if (typeof turnstile !== 'undefined') {
                        clearInterval(poll);
                        executeTurnstile(callback);
                    } else if (attempts >= 50) {
                        clearInterval(poll);
                        callback('');
                    }
                }, 100);
                return;
            }
            executeTurnstile(callback);
        }

        function executeTurnstile(callback) {
            var container = document.getElementById('fm-turnstile-' + $wrap.attr('data-form-id'));
            if (!container) { callback(''); return; }
            turnstilePendingCallback = callback;
            if (turnstileWidgetId === null) {
                renderTurnstileWidget(container);
            } else {
                try { turnstile.reset(turnstileWidgetId); } catch(e) {}
            }
            try { turnstile.execute(turnstileWidgetId); } catch(e) { callback(''); }
        }

        function submitForm(captchaToken) {
            var $submitBtn = $form.find('.fm-btn-submit');
            var $overlay = $wrap.find('.fm-submit-overlay');
            var $progressBar = $overlay.find('.fm-submit-progress-bar');
            var formData;
            var ajaxDone = false;
            var ajaxResponse = null;
            var ajaxFailed = false;

            // Show the progress overlay
            showOverlay();

            // Animate progress bar: fast to 70%, then slow crawl
            $progressBar.css('width', '0%');
            setTimeout(function() {
                $progressBar.css('width', '70%');
            }, 50);

            var ajaxOpts = {
                url: formulariosFront.ajax_url,
                type: 'POST',
                dataType: 'json',
                success: function(response) {
                    ajaxDone = true;
                    ajaxResponse = response;
                },
                error: function() {
                    ajaxDone = true;
                    ajaxFailed = true;
                }
            };

            if (hasFileUpload) {
                formData = new FormData($form[0]);
                formData.append('action', 'formularios_submit');
                if (captchaToken) {
                    formData.append('formularios_captcha_token', captchaToken);
                }
                ajaxOpts.data = formData;
                ajaxOpts.processData = false;
                ajaxOpts.contentType = false;
            } else {
                var serialized = $form.serialize();
                serialized += '&action=formularios_submit';
                if (captchaToken) {
                    serialized += '&formularios_captcha_token=' + encodeURIComponent(captchaToken);
                }
                ajaxOpts.data = serialized;
            }

            $.ajax(ajaxOpts);

            // Poll until ajax completes, then fill bar to 100% and show result
            var poll = setInterval(function() {
                if (!ajaxDone) return;
                clearInterval(poll);

                // Fill to 100%
                $progressBar.css({ width: '100%', transition: 'width 0.3s ease' });

                setTimeout(function() {
                    if (ajaxFailed) {
                        hideOverlay();
                        alert(i18n.error_generic || 'Ocurrio un error. Intenta de nuevo.');
                        $submitBtn.prop('disabled', false).text($submitBtn.data('original-text') || 'Enviar');
                        return;
                    }

                    if (ajaxResponse && ajaxResponse.success) {
                        // Transition from overlay to success
                        $overlay.fadeOut(300, function() {
                            $wrap.find('.fm-success-message').fadeIn(400);
                        });
                    } else {
                        hideOverlay();
                        if (ajaxResponse && ajaxResponse.data && ajaxResponse.data.validation) {
                            showValidationErrors(ajaxResponse.data.validation);
                        } else if (ajaxResponse && ajaxResponse.data && typeof ajaxResponse.data === 'string') {
                            alert(ajaxResponse.data);
                        } else {
                            alert(i18n.error_generic || 'Ocurrio un error. Intenta de nuevo.');
                        }
                        $submitBtn.prop('disabled', false).text($submitBtn.data('original-text') || 'Enviar');
                    }
                }, 400);
            }, 100);
        }

        function showOverlay() {
            var $overlay = $wrap.find('.fm-submit-overlay');
            var $progressBar = $overlay.find('.fm-submit-progress-bar');
            // Reset progress bar transition for the 0→70% phase
            $progressBar.css({ width: '0%', transition: 'width 1.8s cubic-bezier(0.4, 0, 0.2, 1)' });
            $form.css({ opacity: 0, transform: 'scale(0.97)', transition: 'opacity 0.3s ease, transform 0.3s ease', pointerEvents: 'none' });
            setTimeout(function() {
                $form.hide();
                $overlay.css('display', 'flex').hide().fadeIn(250);
            }, 300);
        }

        function hideOverlay() {
            var $overlay = $wrap.find('.fm-submit-overlay');
            $overlay.fadeOut(200, function() {
                $form.css({ opacity: '', transform: '', transition: '', pointerEvents: '', display: '' }).show();
            });
        }

        // Store original button text
        var $submitBtn = $form.find('.fm-btn-submit');
        $submitBtn.data('original-text', $submitBtn.text());

        // --- Input guards (impiden escribir caracteres no permitidos) ---

        // \p{L} necesita el flag "u" (ES2018). Si el navegador no lo soporta,
        // se cae a un rango latino, en vez de romper todo el script.
        var SUPPORTS_UNICODE_PROP = (function() {
            try { new RegExp('\\p{L}', 'u'); return true; } catch (e) { return false; }
        })();
        var L = SUPPORTS_UNICODE_PROP ? '\\p{L}' : 'A-Za-z\\u00C0-\\u024F';
        var UFLAG = SUPPORTS_UNICODE_PROP ? 'u' : '';

        var FORMAT_STRIP = {
            letters:      new RegExp('[^' + L + '\\s\'.\\-]', 'g' + UFLAG),
            numbers:      /[^0-9]/g,
            alphanumeric: new RegExp('[^' + L + '0-9\\s\'.\\-]', 'g' + UFLAG)
        };

        var FORMAT_TEST = {
            letters:      new RegExp('^[' + L + '\\s\'.\\-]+$', UFLAG),
            numbers:      /^[0-9]+$/,
            alphanumeric: new RegExp('^[' + L + '0-9\\s\'.\\-]+$', UFLAG)
        };

        var FORMAT_MESSAGES = {
            letters:      'Este campo solo admite letras.',
            numbers:      'Este campo solo admite numeros.',
            alphanumeric: 'Este campo solo admite letras y numeros.'
        };

        // Recalcula los limites "hoy" en el cliente: la pagina puede venir
        // de una cache generada otro dia.
        $form.find('.fm-field[data-type="date"]').each(function() {
            var $field = $(this);
            var $input = $field.find('.fm-control');
            var today = todayISO();
            if ($field.attr('data-date-min-today') === '1') $input.attr('min', today);
            if ($field.attr('data-date-max-today') === '1') $input.attr('max', today);
        });

        $form.on('input', '.fm-control', function() {
            var $field = $(this).closest('.fm-field');
            var type = $field.data('type');
            var original = $(this).val();
            if (typeof original !== 'string' || original === '') return;
            var cleaned = original;

            if (type === 'number') {
                if ($field.attr('data-integer-only') === '1') {
                    cleaned = cleaned.replace(/[^0-9\-]/g, '').replace(/(?!^)-/g, '');
                }
            } else {
                var format = $field.attr('data-format');
                if (format && FORMAT_STRIP[format]) {
                    cleaned = cleaned.replace(FORMAT_STRIP[format], '');
                }
            }

            var maxLength = parseInt($field.attr('data-max-length'), 10);
            if (maxLength > 0) {
                // input[type=number] ignora maxlength, asi que se recorta aqui.
                var digits = type === 'number' ? cleaned.replace(/[^0-9]/g, '') : cleaned;
                if (digits.length > maxLength) {
                    cleaned = type === 'number'
                        ? (cleaned.charAt(0) === '-' ? '-' : '') + digits.slice(0, maxLength)
                        : cleaned.slice(0, maxLength);
                }
            }

            if (cleaned !== original) {
                // selectionStart no existe (o lanza) en number/date segun el navegador.
                var pos = null;
                try { pos = this.selectionStart; } catch (err) { pos = null; }
                $(this).val(cleaned);
                // Mantener el cursor donde estaba tras quitar caracteres.
                if (pos !== null && this.type !== 'number' && this.type !== 'date') {
                    var offset = original.length - cleaned.length;
                    try { this.setSelectionRange(pos - offset, pos - offset); } catch (err2) {}
                }
            }
        });

        // Bloquear teclas no numericas (incluido "e", "+" de input[type=number])
        $form.on('keypress', '.fm-control', function(e) {
            var $field = $(this).closest('.fm-field');
            var isIntOnly = $field.data('type') === 'number' && $field.attr('data-integer-only') === '1';
            var isNumFormat = $field.attr('data-format') === 'numbers';
            if (!isIntOnly && !isNumFormat) return;
            if (e.ctrlKey || e.metaKey || e.which < 32) return;
            var ch = String.fromCharCode(e.which);

            // El signo negativo se admite mientras no haya otro ya escrito.
            // (input[type=number] no expone el caret, asi que no se puede
            // exigir aqui que vaya al inicio: de eso se encarga el handler
            // de "input", que descarta cualquier signo mal ubicado.)
            if (isIntOnly && ch === '-' && (this.value || '').indexOf('-') === -1) return;

            if (!/[0-9]/.test(ch)) e.preventDefault();
        });

        function todayISO() {
            var d = new Date();
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return d.getFullYear() + '-' + m + '-' + day;
        }

        function formatDate(iso) {
            var parts = iso.split('-');
            return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : iso;
        }

        // Clear error on input
        $form.on('input change', '.fm-control, .fm-choice input, .fm-file-input', function() {
            $(this).closest('.fm-field').removeClass('has-error')
                   .find('.fm-error-msg').hide().text('');
        });

        // Toggle filled state on controls
        $form.on('input change', '.fm-control', function() {
            var val = $(this).val();
            $(this).toggleClass('fm-filled', val !== null && val !== '');
        });

        // Initialize filled state on page load
        $form.find('.fm-control').each(function() {
            var val = $(this).val();
            $(this).toggleClass('fm-filled', val !== null && val !== '');
        });

        // Clear email match error on confirm field input
        $form.on('input', '.fm-email-confirm', function() {
            $(this).closest('.fm-field').find('.fm-email-match-error').hide().text('');
        });

        // --- File Upload Queue ---

        // Each file input gets a managed file queue via DataTransfer
        $form.find('.fm-file-input').each(function() {
            var input = this;
            input._fileQueue = new DataTransfer();
        });

        // Click on dropzone triggers the hidden file input
        $form.on('click', '.fm-file-dropzone', function() {
            $(this).closest('.fm-file-upload-wrap').find('.fm-file-input').trigger('click');
        });

        // Keyboard accessibility for dropzone
        $form.on('keydown', '.fm-file-dropzone', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $(this).trigger('click');
            }
        });

        // Drag & drop on dropzone
        $form.on('dragover dragenter', '.fm-file-dropzone', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('fm-file-dropzone-hover');
        });

        $form.on('dragleave drop', '.fm-file-dropzone', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('fm-file-dropzone-hover');
        });

        $form.on('drop', '.fm-file-dropzone', function(e) {
            var files = e.originalEvent.dataTransfer.files;
            if (!files || files.length === 0) return;
            var $wrap = $(this).closest('.fm-file-upload-wrap');
            var input = $wrap.find('.fm-file-input')[0];
            for (var i = 0; i < files.length; i++) {
                input._fileQueue.items.add(files[i]);
            }
            input.files = input._fileQueue.files;
            renderFileList($wrap);
            $wrap.closest('.fm-field').removeClass('has-error')
                 .find('.fm-error-msg').hide().text('');
        });

        // When files are selected via the native input, append them to the queue
        $form.on('change', '.fm-file-input', function() {
            var input = this;
            var $wrap = $(this).closest('.fm-file-upload-wrap');
            for (var i = 0; i < this.files.length; i++) {
                input._fileQueue.items.add(this.files[i]);
            }
            input.files = input._fileQueue.files;
            renderFileList($wrap);
        });

        // Remove a file from the queue
        $form.on('click', '.fm-file-remove-btn', function() {
            var idx = $(this).data('index');
            var $wrap = $(this).closest('.fm-file-upload-wrap');
            var input = $wrap.find('.fm-file-input')[0];
            input._fileQueue.items.remove(idx);
            input.files = input._fileQueue.files;
            renderFileList($wrap);
        });

        function renderFileList($wrap) {
            var input = $wrap.find('.fm-file-input')[0];
            var $list = $wrap.find('.fm-file-list');
            var $dropzone = $wrap.find('.fm-file-dropzone');
            $list.empty();

            if (!input.files || input.files.length === 0) {
                $dropzone.removeClass('fm-filled');
                return;
            }

            $dropzone.addClass('fm-filled');

            for (var i = 0; i < input.files.length; i++) {
                var file = input.files[i];
                var sizeStr = file.size < 1024 * 1024
                    ? (file.size / 1024).toFixed(1) + ' KB'
                    : (file.size / (1024 * 1024)).toFixed(1) + ' MB';
                var $item = $('<li class="fm-file-item">' +
                    '<span class="fm-file-item-icon">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' +
                    '</span>' +
                    '<span class="fm-file-item-name">' + escHtml(file.name) + '</span>' +
                    '<span class="fm-file-item-size">' + escHtml(sizeStr) + '</span>' +
                    '<button type="button" class="fm-file-remove-btn" data-index="' + i + '" title="Quitar archivo">&times;</button>' +
                '</li>');
                $list.append($item);
            }
        }

        function escHtml(str) {
            return $('<span>').text(str).html();
        }

        // --- Section Navigation ---

        function showSection(num) {
            $sections.removeClass('active');
            $sections.filter('[data-section="' + num + '"]').addClass('active');
        }

        function updateProgress() {
            var $visible = $sections.not('.fm-section-hidden');
            var totalVisible = $visible.length || 1;
            var currentIndex = 0;
            $visible.each(function(i) {
                if (parseInt($(this).attr('data-section'), 10) === currentSection) {
                    currentIndex = i + 1;
                }
            });
            var pct = (currentIndex / totalVisible) * 100;
            $form.find('.fm-progress-fill').css('width', pct + '%');
        }

        function updateNavButtons() {
            var $prev = $wrap.find('.fm-btn-prev');
            var $next = $wrap.find('.fm-btn-next');
            var $submit = $wrap.find('.fm-btn-submit');

            if (hasBranching || hasConditions) {
                $prev.toggle(sectionHistory.length > 1);
            } else {
                $prev.toggle(currentSection > 1);
            }

            var nextSection = determineNextSection(currentSection);
            var isLast = nextSection === '__end__' || getNextVisibleSection(currentSection + 1) < 0;
            if (isLast) {
                $next.hide();
                $submit.show();
            } else {
                $next.show();
                $submit.hide();
            }
        }

        function determineNextSection(fromSection) {
            if (hasBranching) {
                var $currentSection = $sections.filter('[data-section="' + fromSection + '"]');

                for (var fieldName in branchingMap) {
                    var $field = $currentSection.find('[name="' + fieldName + '"]');
                    var selectedValue = '';

                    if ($field.is('select')) {
                        selectedValue = $field.val();
                    } else if ($field.is(':radio')) {
                        selectedValue = $currentSection.find('[name="' + fieldName + '"]:checked').val() || '';
                    }

                    if (selectedValue && branchingMap[fieldName][selectedValue]) {
                        var target = branchingMap[fieldName][selectedValue];

                        if (target === '__end__') {
                            return '__end__';
                        }

                        var targetNum = -1;
                        $sections.each(function() {
                            if ($(this).attr('data-section-id') === target) {
                                targetNum = parseInt($(this).attr('data-section'), 10);
                            }
                        });

                        if (targetNum > 0) {
                            return hasConditions ? getNextVisibleSection(targetNum) : targetNum;
                        }
                    }
                }
            }

            return getNextVisibleSection(fromSection + 1);
        }

        // Update nav buttons when a branching field changes
        if (hasBranching) {
            $form.on('change', 'select, input[type="radio"]', function() {
                updateNavButtons();
            });
        }

        // Evaluate conditions when multiple-choice fields change
        if (hasConditions) {
            $form.on('change', 'select, input[type="radio"], input[type="checkbox"]', function() {
                evaluateConditions();
                if ($sections.filter('[data-section="' + currentSection + '"]').hasClass('fm-section-hidden')) {
                    var next = getNextVisibleSection(1);
                    if (next > 0) {
                        currentSection = next;
                        sectionHistory = [currentSection];
                        showSection(currentSection);
                    }
                }
                updateProgress();
                updateNavButtons();
            });
        }

        // --- Conditional Logic ---

        function evaluateConditions() {
            $sections.each(function() {
                var sectionId = $(this).attr('data-section-id');
                var config = conditionsMap[sectionId];
                if (!config) return;

                var rules = config.rules || [];
                if (rules.length === 0) return;

                var results = rules.map(function(rule) {
                    var values = getFieldSelectedValues(rule.field);
                    if (rule.operator === 'is') {
                        return values.indexOf(rule.value) !== -1;
                    }
                    return values.indexOf(rule.value) === -1;
                });

                var visible;
                if (config.match === 'all') {
                    visible = results.every(function(r) { return r; });
                } else {
                    visible = results.some(function(r) { return r; });
                }

                if (visible) {
                    $(this).removeClass('fm-section-hidden');
                } else {
                    $(this).addClass('fm-section-hidden');
                }
            });
        }

        function getFieldSelectedValues(fieldName) {
            var values = [];
            var $select = $form.find('select[name="' + fieldName + '"]');
            if ($select.length) {
                var val = $select.val();
                if (val) values.push(val);
                return values;
            }
            var $radio = $form.find('input[type="radio"][name="' + fieldName + '"]');
            if ($radio.length) {
                var checked = $radio.filter(':checked').val();
                if (checked) values.push(checked);
                return values;
            }
            $form.find('input[type="checkbox"][name="' + fieldName + '[]"]').filter(':checked').each(function() {
                values.push($(this).val());
            });
            return values;
        }

        function getNextVisibleSection(fromNum) {
            for (var n = fromNum; n <= totalSections; n++) {
                var $sec = $sections.filter('[data-section="' + n + '"]');
                if ($sec.length && !$sec.hasClass('fm-section-hidden')) {
                    return n;
                }
            }
            return -1;
        }

        function getPrevVisibleSection(fromNum) {
            for (var n = fromNum; n >= 1; n--) {
                var $sec = $sections.filter('[data-section="' + n + '"]');
                if ($sec.length && !$sec.hasClass('fm-section-hidden')) {
                    return n;
                }
            }
            return -1;
        }

        // --- Validation ---

        function validateSection(num) {
            var $section = $sections.filter('[data-section="' + num + '"]');
            return validateFields($section.find('.fm-field'));
        }

        function validateAll() {
            return validateFields($form.find('.fm-field'));
        }

        function validateFields($fields) {
            var valid = true;
            $fields.each(function() {
                var $field = $(this);
                var $control = $field.find('.fm-control');
                var $errorMsg = $field.find('.fm-error-msg');
                var isRequired = $field.attr('data-required') === '1';

                $field.removeClass('has-error');
                $errorMsg.hide().text('');

                var value = '';
                var type = $field.data('type');

                if (type === 'radio') {
                    value = $field.find('input[type="radio"]:checked').val() || '';
                } else if (type === 'checkbox') {
                    value = $field.find('input[type="checkbox"]:checked').length > 0 ? 'yes' : '';
                } else if (type === 'consent') {
                    value = $field.find('input[type="checkbox"]:checked').length > 0 ? 'yes' : '';
                } else if (type === 'file') {
                    var fileInput = $field.find('.fm-file-input')[0];
                    value = (fileInput && fileInput.files && fileInput.files.length > 0) ? 'yes' : '';
                } else {
                    value = $control.val() || '';
                }

                if (value.trim() === '') {
                    if (!isRequired) return;
                    $field.addClass('has-error');
                    var customMsg = $field.attr('data-required-msg');
                    var emptyMsg = type === 'consent'
                        ? (i18n.consent_error || 'Debes aceptar para continuar.')
                        : (i18n.required_error || 'Este campo es obligatorio.');
                    $errorMsg.text(customMsg || emptyMsg).show();
                    valid = false;
                    return;
                }

                // Formato, longitud, rango y limites de fecha
                var constraintError = checkConstraints($field, type, value.trim());
                if (constraintError) {
                    $field.addClass('has-error');
                    $errorMsg.text(constraintError).show();
                    valid = false;
                    return;
                }

                // Email validation
                if (type === 'email' && value) {
                    var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(value)) {
                        $field.addClass('has-error');
                        var emailErr = $field.attr('data-email-error');
                        $errorMsg.text(emailErr || i18n.email_error || 'Ingresa un email valido.').show();
                        valid = false;
                    } else {
                        // Check email confirmation match
                        var $confirm = $field.find('.fm-email-confirm');
                        var $matchError = $field.find('.fm-email-match-error');
                        if ($confirm.length) {
                            var confirmVal = $confirm.val().trim();
                            if (confirmVal === '' && isRequired) {
                                $field.addClass('has-error');
                                var mismatchErr = $field.attr('data-email-mismatch-error');
                                $matchError.text(mismatchErr || i18n.email_confirm_required || 'Confirma tu email.').show();
                                valid = false;
                            } else if (confirmVal !== '' && value.toLowerCase() !== confirmVal.toLowerCase()) {
                                $field.addClass('has-error');
                                var mismatchErr2 = $field.attr('data-email-mismatch-error');
                                $matchError.text(mismatchErr2 || i18n.email_mismatch || 'Los emails no coinciden.').show();
                                valid = false;
                            }
                        }
                    }
                }
            });
            return valid;
        }

        /**
         * Reglas de formato, longitud, rango y fecha.
         * Replica lo que valida el servidor, para avisar antes de enviar.
         *
         * @return {string} Mensaje de error, o '' si el valor es valido.
         */
        function checkConstraints($field, type, value) {
            var formatError = $field.attr('data-format-error') || '';
            var dateError   = $field.attr('data-date-error') || '';
            var numberError = $field.attr('data-number-error') || '';
            var minLength   = parseInt($field.attr('data-min-length'), 10);
            var maxLength   = parseInt($field.attr('data-max-length'), 10);
            var $control    = $field.find('.fm-control').first();

            if (type === 'date') {
                if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                    return dateError || 'Ingresa una fecha valida.';
                }
                var minDate = $control.attr('min') || '';
                var maxDate = $control.attr('max') || '';
                if (minDate && value < minDate) {
                    return dateError || 'La fecha no puede ser anterior al ' + formatDate(minDate) + '.';
                }
                if (maxDate && value > maxDate) {
                    if (dateError) return dateError;
                    return maxDate === todayISO()
                        ? 'No se pueden seleccionar fechas futuras.'
                        : 'La fecha no puede ser posterior al ' + formatDate(maxDate) + '.';
                }
                return '';
            }

            if (type === 'number') {
                if ($field.attr('data-integer-only') === '1') {
                    if (!/^-?[0-9]+$/.test(value)) {
                        return numberError || 'Ingresa un numero entero, sin letras ni simbolos.';
                    }
                } else if (!isFinite(value) || value === '') {
                    return numberError || 'Ingresa un numero valido.';
                }

                var minValue = $control.attr('min');
                var maxValue = $control.attr('max');
                if (minValue !== undefined && minValue !== '' && parseFloat(value) < parseFloat(minValue)) {
                    return numberError || 'El valor minimo permitido es ' + minValue + '.';
                }
                if (maxValue !== undefined && maxValue !== '' && parseFloat(value) > parseFloat(maxValue)) {
                    return numberError || 'El valor maximo permitido es ' + maxValue + '.';
                }

                var digits = value.replace(/[^0-9]/g, '').length;
                if (minLength > 0 && digits < minLength) {
                    return numberError || 'Debe tener al menos ' + minLength + ' digitos.';
                }
                if (maxLength > 0 && digits > maxLength) {
                    return numberError || 'No puede superar los ' + maxLength + ' digitos.';
                }
                return '';
            }

            if (type === 'text' || type === 'textarea') {
                var format = $field.attr('data-format');
                if (format && FORMAT_TEST[format] && !FORMAT_TEST[format].test(value)) {
                    return formatError || FORMAT_MESSAGES[format];
                }
                if (minLength > 0 && value.length < minLength) {
                    return formatError || 'Debe tener al menos ' + minLength + ' caracteres.';
                }
                if (maxLength > 0 && value.length > maxLength) {
                    return formatError || 'No puede superar los ' + maxLength + ' caracteres.';
                }
            }

            return '';
        }

        function validateFiles() {
            var valid = true;
            $form.find('.fm-file-input').each(function() {
                var $input = $(this);
                var $field = $input.closest('.fm-field');
                var $errorMsg = $field.find('.fm-error-msg');

                if (!this.files || this.files.length === 0) return;

                var maxSize = parseInt($input.attr('data-max-size') || '5', 10);
                var maxBytes = maxSize * 1024 * 1024;
                var accept = $input.attr('accept') || '';
                var acceptList = accept ? accept.toLowerCase().split(',').map(function(s) { return s.trim(); }) : [];

                for (var i = 0; i < this.files.length; i++) {
                    if (this.files[i].size > maxBytes) {
                        $field.addClass('has-error');
                        var sizeErr = $field.attr('data-file-size-error');
                        $errorMsg.text(sizeErr || '"' + this.files[i].name + '" ' + (i18n.file_too_large || 'es demasiado grande.') + ' Max: ' + maxSize + ' MB').show();
                        valid = false;
                        return false;
                    }
                    if (acceptList.length) {
                        var ext = '.' + this.files[i].name.split('.').pop().toLowerCase();
                        if (acceptList.indexOf(ext) === -1) {
                            $field.addClass('has-error');
                            var typeErr = $field.attr('data-file-type-error');
                            $errorMsg.text(typeErr || (i18n.file_type_err || 'Tipo de archivo no permitido.') + ' ' + this.files[i].name).show();
                            valid = false;
                            return false;
                        }
                    }
                }
            });
            return valid;
        }

        function showValidationErrors(errors) {
            for (var name in errors) {
                var $input = $form.find('[name="' + name + '"], [name="' + name + '[]"]').first();
                var $field = $input.closest('.fm-field');
                $field.addClass('has-error');
                $field.find('.fm-error-msg').text(errors[name]).show();
            }
        }

        function scrollToTop() {
            $('html, body').animate({
                scrollTop: $wrap.offset().top - 40
            }, 300);
        }
    }

})(jQuery);
