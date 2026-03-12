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

        // Track section visit history for back navigation with branching
        var sectionHistory = [1];

        if (hasSections) {
            updateProgress();
            updateNavButtons();
        }

        // Next button
        $wrap.on('click', '.fm-btn-next', function() {
            if (!validateSection(currentSection)) return;

            var nextSection = determineNextSection(currentSection);

            if (nextSection === '__end__') {
                // Submit the form
                $form.trigger('submit');
                return;
            }

            if (nextSection > 0 && nextSection <= totalSections) {
                currentSection = nextSection;
                sectionHistory.push(currentSection);
                showSection(currentSection);
                updateProgress();
                updateNavButtons();
                scrollToTop();
            }
        });

        // Previous button
        $wrap.on('click', '.fm-btn-prev', function() {
            if (hasBranching && sectionHistory.length > 1) {
                sectionHistory.pop();
                currentSection = sectionHistory[sectionHistory.length - 1];
            } else {
                currentSection--;
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

            var $submitBtn = $form.find('.fm-btn-submit');
            $submitBtn.prop('disabled', true).text(i18n.sending || 'Enviando...');

            var formData = $form.serialize();
            formData += '&action=formularios_submit';

            $.ajax({
                url: formulariosFront.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        $form.fadeOut(300, function() {
                            $wrap.find('.fm-success-message').fadeIn(300);
                        });
                    } else {
                        if (response.data && response.data.validation) {
                            showValidationErrors(response.data.validation);
                        }
                        $submitBtn.prop('disabled', false).text($submitBtn.data('original-text') || 'Enviar');
                    }
                },
                error: function() {
                    alert(i18n.error_generic || 'Ocurrio un error. Intenta de nuevo.');
                    $submitBtn.prop('disabled', false).text($submitBtn.data('original-text') || 'Enviar');
                }
            });
        });

        // Store original button text
        var $submitBtn = $form.find('.fm-btn-submit');
        $submitBtn.data('original-text', $submitBtn.text());

        // Clear error on input
        $form.on('input change', '.fm-control, .fm-choice input', function() {
            $(this).closest('.fm-field').removeClass('has-error')
                   .find('.fm-error-msg').hide().text('');
        });

        // --- Section Navigation ---

        function showSection(num) {
            $sections.removeClass('active');
            $sections.filter('[data-section="' + num + '"]').addClass('active');
        }

        function updateProgress() {
            var pct = (currentSection / totalSections) * 100;
            $form.find('.fm-progress-fill').css('width', pct + '%');
        }

        function updateNavButtons() {
            var $prev = $wrap.find('.fm-btn-prev');
            var $next = $wrap.find('.fm-btn-next');
            var $submit = $wrap.find('.fm-btn-submit');

            if (hasBranching) {
                $prev.toggle(sectionHistory.length > 1);
            } else {
                $prev.toggle(currentSection > 1);
            }

            // For branching: check if current section might end the form
            var nextSection = determineNextSection(currentSection);
            if (nextSection === '__end__' || currentSection === totalSections) {
                $next.hide();
                $submit.show();
            } else {
                $next.toggle(currentSection < totalSections);
                $submit.toggle(currentSection === totalSections);
            }
        }

        /**
         * Determine which section to go to next based on branching rules.
         * Returns section number (1-based), '__end__', or currentSection + 1.
         */
        function determineNextSection(fromSection) {
            if (!hasBranching) return fromSection + 1;

            var $currentSection = $sections.filter('[data-section="' + fromSection + '"]');

            // Check all branching fields in the current section
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

                    // Find section number by section ID
                    var targetNum = -1;
                    $sections.each(function() {
                        if ($(this).attr('data-section-id') === target) {
                            targetNum = parseInt($(this).attr('data-section'), 10);
                        }
                    });

                    if (targetNum > 0) {
                        return targetNum;
                    }
                }
            }

            return fromSection + 1;
        }

        // Update nav buttons when a branching field changes
        if (hasBranching) {
            $form.on('change', 'select, input[type="radio"]', function() {
                updateNavButtons();
            });
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
                var isRequired = $control.is('[required]') || $field.find('input[required]').length > 0;

                $field.removeClass('has-error');
                $errorMsg.hide().text('');

                if (!isRequired) return;

                var value = '';
                var type = $field.data('type');

                if (type === 'radio') {
                    value = $field.find('input[type="radio"]:checked').val() || '';
                } else if (type === 'checkbox') {
                    value = $field.find('input[type="checkbox"]:checked').length > 0 ? 'yes' : '';
                } else {
                    value = $control.val() || '';
                }

                if (value.trim() === '') {
                    $field.addClass('has-error');
                    $errorMsg.text(i18n.required_error || 'Este campo es obligatorio.').show();
                    valid = false;
                    return;
                }

                // Email validation
                if (type === 'email' && value) {
                    var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(value)) {
                        $field.addClass('has-error');
                        $errorMsg.text(i18n.email_error || 'Ingresa un email valido.').show();
                        valid = false;
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
