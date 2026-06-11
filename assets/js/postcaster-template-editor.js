/**
 * PostCaster template-editor wiring.
 *
 * Hooks the per-toggle "Custom template" UI: enables/disables the textarea,
 * keeps the inherited fallback in sync, refreshes the preview-items via
 * AJAX, and renders preview cards (matching the server-side
 * justbee_postcaster_render_compose_preview_items markup).
 *
 * The script reads everything it needs from data-* attributes on the page;
 * translatable strings come from the preview button's data-postcaster-preview-*
 * attributes set in TemplateEditorRenderer::renderEditorMarkup().
 *
 * Avatar palettes per network are localised onto window.justbeePostcasterAdmin
 * via wp_localize_script in Plugin::enqueueAdminAssets().
 */
(function () {
    'use strict';

    var defaultPalette = { background: '#eef2f7', color: '#334155' };

    function getPalettes() {
        var data = window.justbeePostcasterAdmin || {};
        return (data && typeof data.avatarPalettes === 'object' && data.avatarPalettes) || {};
    }

    function getAvatarPalette(networkKey) {
        var palettes = getPalettes();
        return palettes[networkKey] || defaultPalette;
    }

    function escapeHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildItemHtml(item, key, index) {
        if (!item || (typeof item.text !== 'string' && !item.card)) {
            return '';
        }

        var headerHtml = '';
        if (item.header && typeof item.header === 'object') {
            var palette = getAvatarPalette(item.header.network || item.network || '');
            var avatarStyle = 'background:' + palette.background + ';color:' + palette.color + ';';
            headerHtml = '<div class="postcaster-preview-item__header">' +
                '<div class="postcaster-preview-item__avatar" style="' + avatarStyle + '">' + escapeHtml(item.header.avatar_text || '?') + '</div>' +
                '<div class="postcaster-preview-item__identity">' +
                    '<div class="postcaster-preview-item__name">' + escapeHtml(item.header.name || item.label || '') + '</div>' +
                    '<div class="postcaster-preview-item__meta">' + escapeHtml(item.header.meta || '') + '</div>' +
                '</div>' +
            '</div>';
        } else if (item.label) {
            headerHtml = '<div style="padding:0 0 8px;font-size:12px;font-weight:600;color:#50575e;">' + escapeHtml(item.label) + '</div>';
        }

        var textAttr = index === 0 ? ' data-postcaster-template-preview="' + escapeHtml(key) + '"' : '';
        var textHtml = '<div class="postcaster-preview-item__text"' + textAttr + '>' + escapeHtml(item.text || '') + '</div>';

        var mediaHtml = '';
        if (item.card && item.card.url && item.card.title) {
            var cardImageHtml = item.card.image_url
                ? '<div class="postcaster-preview-item__card-image"><img src="' + escapeHtml(item.card.image_url) + '" alt="' + escapeHtml(item.card.image_alt || '') + '"></div>'
                : '';
            var hiddenImageHtml = item.image && item.image.url
                ? '<div class="postcaster-preview-item__image-wrap" data-postcaster-template-preview-image-wrap="' + escapeHtml(key) + '" hidden style="display:none;"><img data-postcaster-template-preview-image="' + escapeHtml(key) + '" src="' + escapeHtml(item.image.url) + '" alt="' + escapeHtml(item.image.alt || '') + '"></div>'
                : '';

            mediaHtml = '<div class="postcaster-preview-item__card" data-postcaster-template-preview-card-wrap="' + escapeHtml(key) + '">' +
                cardImageHtml +
                '<div class="postcaster-preview-item__card-body">' +
                    '<div class="postcaster-preview-item__card-title">' + escapeHtml(item.card.title) + '</div>' +
                    '<div class="postcaster-preview-item__card-description">' + escapeHtml(item.card.description || '') + '</div>' +
                '</div>' +
                '<div class="postcaster-preview-item__card-domain">' + escapeHtml(item.card.domain || '') + '</div>' +
            '</div>' + hiddenImageHtml;
        } else if (item.image && item.image.url) {
            mediaHtml = '<div class="postcaster-preview-item__image-wrap" data-postcaster-template-preview-image-wrap="' + escapeHtml(key) + '"><img data-postcaster-template-preview-image="' + escapeHtml(key) + '" src="' + escapeHtml(item.image.url) + '" alt="' + escapeHtml(item.image.alt || '') + '"></div>';
        }

        var warningHtml = '';
        if (typeof item.warning === 'string' && item.warning !== '') {
            warningHtml = '<div class="postcaster-preview-item__warning notice notice-warning inline" style="margin:8px 0 0;padding:6px 10px;font-size:12px;">' +
                escapeHtml(item.warning) +
            '</div>';
        }

        return '<div class="postcaster-preview-item" data-postcaster-template-preview-item-wrap="' + escapeHtml(key) + '">' +
            headerHtml + textHtml + mediaHtml + warningHtml +
        '</div>';
    }

    function bindControl(control) {
        var key = control.getAttribute('data-postcaster-template-control');
        var wrappers = Array.prototype.slice.call(document.querySelectorAll('[data-postcaster-template-wrapper="' + key + '"]'));
        if (!wrappers.length) {
            return;
        }

        function findInWrappers(selector) {
            for (var index = 0; index < wrappers.length; index += 1) {
                var match = wrappers[index].querySelector(selector);
                if (match) { return match; }
            }
            return null;
        }

        var textarea = findInWrappers('[data-postcaster-template-editor="' + key + '"]');
        if (!textarea) {
            return;
        }

        var previewButton = findInWrappers('[data-postcaster-preview-button]');
        var customizeButton = findInWrappers('[data-postcaster-template-customize-button="' + key + '"]');
        var previewContainer = findInWrappers('[data-postcaster-template-preview-items="' + key + '"]');
        var previewContainers = [];
        wrappers.forEach(function (wrapper) {
            Array.prototype.slice.call(wrapper.querySelectorAll('[data-postcaster-template-preview-items="' + key + '"]')).forEach(function (el) {
                previewContainers.push(el);
            });
        });
        var previewSaveNote = findInWrappers('[data-postcaster-preview-save-note="' + key + '"]');
        var editorRows = Array.prototype.slice.call(document.querySelectorAll('[data-postcaster-template-editor-row="' + key + '"]'));
        var previewLoaded = false;

        function normalizeTemplateField(isEnabled) {
            if (!textarea || !isEnabled) { return; }
            if (textarea.getAttribute('data-postcaster-normalize-empty') !== '1') { return; }
            if ((textarea.value || '').trim() !== '') { return; }
            textarea.value = textarea.getAttribute('data-postcaster-default-template') || '';
        }

        function renderPreviewItems(items) {
            if (!previewContainers.length) { return; }
            var html = '';
            (Array.isArray(items) ? items : []).forEach(function (item, index) {
                html += buildItemHtml(item, key, index);
            });
            previewContainers.forEach(function (c) { c.innerHTML = html; });
        }

        function syncPreviewButton(isEnabled) {
            if (!previewButton || previewButton.getAttribute('data-postcaster-preview-requires-edit') !== '1') {
                return;
            }
            if (isEnabled) {
                previewButton.hidden = false;
                previewButton.style.display = '';
            } else {
                previewButton.hidden = true;
                previewButton.style.display = 'none';
            }
        }

        function syncCustomizeButton(isEnabled) {
            if (!customizeButton) { return; }
            customizeButton.hidden = !isEnabled;
            customizeButton.style.display = isEnabled ? '' : 'none';
        }

        function getEffectiveTemplate() {
            var value = (textarea.value || '').trim();
            if (value !== '') { return textarea.value || ''; }
            return textarea.getAttribute('data-postcaster-default-template') || '';
        }

        function updateExamplePreview(showSaveNote) {
            if (!previewButton || !previewContainer || previewButton.getAttribute('data-postcaster-preview-type') !== 'example') {
                return;
            }

            var formData = new FormData();
            formData.append('action', 'justbee_postcaster_preview_template_example');
            formData.append('_ajax_nonce', previewButton.getAttribute('data-postcaster-preview-nonce') || '');
            formData.append('network_key', previewButton.getAttribute('data-postcaster-preview-network-key') || '');
            formData.append('scope', previewButton.getAttribute('data-postcaster-preview-scope') || 'global');
            formData.append('user_id', previewButton.getAttribute('data-postcaster-preview-user-id') || '0');
            formData.append('template', getEffectiveTemplate());

            renderPreviewItems([{ label: '', text: previewButton.getAttribute('data-postcaster-preview-loading') || '' }]);

            window.fetch(previewButton.getAttribute('data-postcaster-preview-url') || '', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            }).then(function (response) {
                return response.json();
            }).then(function (payload) {
                if (!payload || !payload.success || !payload.data || typeof payload.data.text !== 'string') {
                    throw new Error('preview_failed');
                }
                renderPreviewItems(payload.data.items || []);
                previewLoaded = true;
                if (showSaveNote && previewSaveNote) {
                    previewSaveNote.hidden = false;
                    previewSaveNote.style.display = '';
                }
            }).catch(function () {
                renderPreviewItems([{ label: '', text: previewButton.getAttribute('data-postcaster-preview-error') || '' }]);
            });
        }

        function sync() {
            if (control.checked) {
                textarea.removeAttribute('readonly');
                var currentTemplate = textarea.getAttribute('data-postcaster-current-template') || '';
                textarea.value = currentTemplate !== ''
                    ? currentTemplate
                    : (textarea.getAttribute('data-postcaster-default-template') || '');
                normalizeTemplateField(true);
            } else {
                textarea.setAttribute('readonly', 'readonly');
                textarea.value = textarea.getAttribute('data-postcaster-inherited-template') || '';
            }

            editorRows.forEach(function (row) {
                row.style.display = control.checked ? '' : 'none';
            });

            syncPreviewButton(control.checked);
            syncCustomizeButton(control.checked);

            if (previewContainer && (!previewLoaded || !control.checked)) {
                updateExamplePreview(false);
            }

            if (!control.checked && previewSaveNote) {
                previewSaveNote.hidden = true;
                previewSaveNote.style.display = 'none';
            }
        }

        control.addEventListener('change', sync);
        textarea.addEventListener('input', function () {
            textarea.setAttribute('data-postcaster-current-template', textarea.value || '');
        });
        textarea.addEventListener('blur', function () {
            normalizeTemplateField(control.checked);
            textarea.setAttribute('data-postcaster-current-template', textarea.value || '');
        });
        if (previewButton && previewButton.getAttribute('data-postcaster-preview-type') === 'example') {
            previewButton.addEventListener('click', function () {
                updateExamplePreview(true);
            });
        }

        var modalSaveButton = findInWrappers('[data-postcaster-template-modal-save="' + key + '"]');
        if (modalSaveButton) {
            modalSaveButton.addEventListener('click', function () {
                var form = modalSaveButton.closest('form');
                if (form) { form.requestSubmit(); }
            });
        }

        sync();
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.slice.call(document.querySelectorAll('[data-postcaster-template-control]')).forEach(bindControl);
    });
})();
