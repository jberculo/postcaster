/**
 * PostCaster post-edit compose box state machine.
 *
 * Reads per-instance data (URLs, nonces, post id) from the
 * [data-postcaster-compose] element. Translatable strings come from
 * window.justbeePostcasterCompose, localised by Plugin::enqueueAdminAssets().
 */
(function () {
    'use strict';

    function init(compose) {
        var i18n = (window.justbeePostcasterCompose && window.justbeePostcasterCompose.i18n) || {};
        var modal = compose.parentNode.querySelector('[data-postcaster-modal]') || document.querySelector('[data-postcaster-modal]');
        if (!modal) {
            return;
        }
        var scopeSelect = compose.querySelector('[data-postcaster-scope-select]');
        var postId = compose.getAttribute('data-postcaster-post-id') || '0';
        var previewUrl = compose.getAttribute('data-postcaster-preview-url') || '';
        var publishUrl = compose.getAttribute('data-postcaster-publish-url') || '';
        var previewNonce = compose.getAttribute('data-postcaster-preview-nonce') || '';
        var publishNonce = compose.getAttribute('data-postcaster-publish-nonce') || '';
        var postNonce = (compose.parentNode.querySelector('input[name="justbee_postcaster_post_nonce"]') || {}).value || '';

        function getDraftInput(scope, kind, network) {
            var selector = '[data-postcaster-draft="' + kind + '"][data-postcaster-scope="' + scope + '"]';
            if (network) {
                selector += '[data-postcaster-network="' + network + '"]';
            }
            return compose.querySelector(selector);
        }

        function setActiveScope(scope) {
            Array.prototype.slice.call(compose.querySelectorAll('[data-postcaster-scope-pane]')).forEach(function (pane) {
                var match = pane.getAttribute('data-postcaster-scope-pane') === scope;
                pane.hidden = !match;
                pane.style.display = match ? '' : 'none';
            });
        }

        function setActiveNetworkInScope(scope, network) {
            Array.prototype.slice.call(compose.querySelectorAll('[data-postcaster-preview-block][data-postcaster-scope="' + scope + '"]')).forEach(function (block) {
                var match = (block.getAttribute('data-postcaster-network') || '') === (network || '');
                block.hidden = !match;
                block.style.display = match ? '' : 'none';
            });
        }

        if (scopeSelect) {
            scopeSelect.addEventListener('change', function () {
                setActiveScope(scopeSelect.value);
            });
        }
        Array.prototype.slice.call(compose.querySelectorAll('[data-postcaster-network-select]')).forEach(function (sel) {
            sel.addEventListener('change', function () {
                setActiveNetworkInScope(sel.getAttribute('data-postcaster-scope') || '', sel.value || '');
            });
        });

        function escapeHtml(text) {
            return String(text == null ? '' : text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderItems(container, items) {
            if (!container) {
                return;
            }
            var html = '';
            (Array.isArray(items) ? items : []).forEach(function (item) {
                if (!item) {
                    return;
                }
                var headerName = (item.header && item.header.name) || item.label || '';
                var headerMeta = (item.header && item.header.meta) || '';
                var avatar = (item.header && item.header.avatar_text) || '?';
                html += '<div class="postcaster-preview-item">';
                if (headerName || headerMeta) {
                    html += '<div class="postcaster-preview-item__header">';
                    html += '<div class="postcaster-preview-item__avatar postcaster-preview-item__avatar--small">' + escapeHtml(avatar) + '</div>';
                    html += '<div class="postcaster-preview-item__identity">';
                    html += '<div class="postcaster-preview-item__name postcaster-preview-item__name--small">' + escapeHtml(headerName) + '</div>';
                    html += '<div class="postcaster-preview-item__meta postcaster-preview-item__meta--small">' + escapeHtml(headerMeta) + '</div>';
                    html += '</div></div>';
                }
                html += '<div class="postcaster-preview-item__text postcaster-preview-item__text--small">' + escapeHtml(item.text || '') + '</div>';
                if (item.card && item.card.url && item.card.title) {
                    html += '<div class="postcaster-preview-item__card postcaster-preview-item__card--compact">';
                    if (item.card.image_url) {
                        html += '<div class="postcaster-preview-item__card-image"><img src="' + escapeHtml(item.card.image_url) + '" alt="' + escapeHtml(item.card.image_alt || '') + '"></div>';
                    }
                    html += '<div class="postcaster-preview-item__card-body">';
                    html += '<div class="postcaster-preview-item__card-title postcaster-preview-item__card-title--compact">' + escapeHtml(item.card.title) + '</div>';
                    html += '<div class="postcaster-preview-item__card-description postcaster-preview-item__card-description--compact">' + escapeHtml(item.card.description || '') + '</div>';
                    html += '<div class="postcaster-preview-item__card-domain postcaster-preview-item__card-domain--compact">' + escapeHtml(item.card.domain || '') + '</div>';
                    html += '</div></div>';
                } else if (item.image && item.image.url) {
                    html += '<div class="postcaster-preview-item__image-wrap"><img src="' + escapeHtml(item.image.url) + '" alt="' + escapeHtml(item.image.alt || '') + '"></div>';
                }
                if (typeof item.warning === 'string' && item.warning !== '') {
                    html += '<div class="postcaster-preview-item__warning notice notice-warning inline" style="margin:6px 0 0;padding:4px 8px;font-size:11px;">' + escapeHtml(item.warning) + '</div>';
                }
                html += '</div>';
            });
            container.innerHTML = html || '<p class="description"><em>' + escapeHtml(i18n.noPreview || 'No preview') + '</em></p>';
        }

        function refreshPreviewBlock(scope, network, template) {
            var selector = '[data-postcaster-preview-items="' + scope + ':' + (network || '') + '"]';
            var container = compose.querySelector(selector);
            if (!container) {
                return Promise.resolve();
            }

            var body = new FormData();
            body.append('action', 'justbee_postcaster_preview_post_template');
            body.append('post_id', postId);
            body.append('_ajax_nonce', previewNonce);
            body.append('template_context', scope);
            body.append('template', template || '');
            if (network) {
                body.append('network_key', network);
            }

            return window.fetch(previewUrl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (payload) {
                    if (payload && payload.success) {
                        renderItems(container, (payload.data || {}).items || []);
                    }
                })
                .catch(function () { /* swallow */ });
        }

        // Modal logic
        var modalTitle = modal.querySelector('[data-postcaster-modal-title]');
        var modalTextarea = modal.querySelector('[data-postcaster-modal-template]');
        var modalPreview = modal.querySelector('[data-postcaster-modal-preview]');
        var modalCancel = modal.querySelector('[data-postcaster-modal-cancel]');
        var modalSave = modal.querySelector('[data-postcaster-modal-save]');
        var modalUpdate = modal.querySelector('[data-postcaster-modal-update]');
        var modalContext = { scope: '', network: '', defaultTemplate: '' };

        function openModal(scope, network, defaultTemplate) {
            // For per-network modals, fall back to the live combined draft when
            // there is no per-network override, so the user sees what the
            // network would currently render (instead of the page-load default).
            if (network) {
                var combinedInput = getDraftInput(scope, 'combined');
                var combinedValue = (combinedInput && combinedInput.value) || '';
                if (combinedValue !== '') {
                    defaultTemplate = combinedValue;
                }
            }
            modalContext = { scope: scope, network: network || '', defaultTemplate: defaultTemplate || '' };
            var templateInput = network
                ? getDraftInput(scope, 'network_template', network)
                : getDraftInput(scope, 'combined');
            var storedTemplate = (templateInput && templateInput.value) || '';
            // Pre-fill with the stored draft when set; otherwise seed with the
            // inherited template so users have a starting point to edit.
            modalTextarea.value = storedTemplate !== '' ? storedTemplate : (defaultTemplate || '');
            modalTextarea.placeholder = defaultTemplate || '';
            var titleNetwork = i18n.customizeForNetwork || 'Customize for %s';
            var titleAll = i18n.customizeForAll || 'Customize for all networks';
            modalTitle.textContent = network ? titleNetwork.replace('%s', network) : titleAll;
            renderItemsFromBlock();
            if (typeof modal.showModal === 'function') {
                modal.showModal();
            } else {
                modal.setAttribute('open', '');
            }
        }

        function renderItemsFromBlock() {
            var blockSelector = '[data-postcaster-preview-block][data-postcaster-scope="' + modalContext.scope + '"][data-postcaster-network="' + (modalContext.network || '') + '"]';
            var block = compose.querySelector(blockSelector);
            modalPreview.innerHTML = block ? block.querySelector('[data-postcaster-preview-items]').innerHTML : '';
        }

        function updateModalPreview() {
            var template = modalTextarea.value || '';
            var body = new FormData();
            body.append('action', 'justbee_postcaster_preview_post_template');
            body.append('post_id', postId);
            body.append('_ajax_nonce', previewNonce);
            body.append('template_context', modalContext.scope);
            body.append('template', template);
            if (modalContext.network) {
                body.append('network_key', modalContext.network);
            }
            window.fetch(previewUrl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (payload) {
                    if (payload && payload.success) {
                        renderItems(modalPreview, (payload.data || {}).items || []);
                    }
                });
        }

        function closeModal() {
            if (typeof modal.close === 'function') {
                modal.close();
            } else {
                modal.removeAttribute('open');
            }
        }

        function saveModal() {
            var template = modalTextarea.value || '';
            var templateInput = modalContext.network
                ? getDraftInput(modalContext.scope, 'network_template', modalContext.network)
                : getDraftInput(modalContext.scope, 'combined');
            if (templateInput) { templateInput.value = template; }
            closeModal();
            refreshPreviewBlock(modalContext.scope, modalContext.network, template);

            // Editing the combined template also refreshes per-network previews
            // that don't have their own override (empty per-network draft).
            if (!modalContext.network) {
                Array.prototype.slice.call(
                    compose.querySelectorAll('[data-postcaster-preview-block][data-postcaster-scope="' + modalContext.scope + '"]')
                ).forEach(function (block) {
                    var network = block.getAttribute('data-postcaster-network') || '';
                    if (network === '') {
                        return;
                    }
                    var override = getDraftInput(modalContext.scope, 'network_template', network);
                    var overrideValue = override && override.value ? override.value : '';
                    refreshPreviewBlock(modalContext.scope, network, overrideValue !== '' ? overrideValue : template);
                });
            }
        }

        modalCancel.addEventListener('click', closeModal);
        modalSave.addEventListener('click', saveModal);
        modalUpdate.addEventListener('click', updateModalPreview);

        Array.prototype.slice.call(compose.querySelectorAll('[data-postcaster-edit]')).forEach(function (btn) {
            btn.addEventListener('click', function () {
                openModal(
                    btn.getAttribute('data-postcaster-scope') || '',
                    btn.getAttribute('data-postcaster-network') || '',
                    btn.getAttribute('data-postcaster-default-template') || ''
                );
            });
        });

        // Confirm-publish modal
        var confirmModal = compose.parentNode.querySelector('[data-postcaster-confirm-modal]') || document.querySelector('[data-postcaster-confirm-modal]');
        var confirmSummary = confirmModal ? confirmModal.querySelector('[data-postcaster-confirm-summary]') : null;
        var confirmOk = confirmModal ? confirmModal.querySelector('[data-postcaster-confirm-ok]') : null;
        var confirmCancel = confirmModal ? confirmModal.querySelector('[data-postcaster-confirm-cancel]') : null;
        var pendingPublishHandler = null;

        function openConfirm(summary, onConfirm) {
            if (!confirmModal) {
                if (window.confirm(summary)) { onConfirm(); }
                return;
            }
            confirmSummary.textContent = summary;
            pendingPublishHandler = onConfirm;
            if (typeof confirmModal.showModal === 'function') {
                confirmModal.showModal();
            } else {
                confirmModal.setAttribute('open', '');
            }
        }

        function closeConfirm() {
            if (!confirmModal) { return; }
            if (typeof confirmModal.close === 'function') {
                confirmModal.close();
            } else {
                confirmModal.removeAttribute('open');
            }
            pendingPublishHandler = null;
        }

        if (confirmCancel) { confirmCancel.addEventListener('click', closeConfirm); }
        if (confirmOk) {
            confirmOk.addEventListener('click', function () {
                var handler = pendingPublishHandler;
                closeConfirm();
                if (typeof handler === 'function') { handler(); }
            });
        }

        Array.prototype.slice.call(compose.querySelectorAll('[data-postcaster-publish]')).forEach(function (btn) {
            btn.addEventListener('click', function () {
                var summary = btn.getAttribute('data-postcaster-publish-summary')
                    || (i18n.confirmPublish || 'Post this article now to the selected target?');
                openConfirm(summary, function () {
                    submitPublish(btn);
                });
            });
        });

        function submitPublish(btn) {
            var form = document.createElement('form');
            form.method = 'post';
            form.action = publishUrl;
            form.style.display = 'none';

            function field(name, value) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            }

            field('action', 'justbee_postcaster_publish_now');
            field('post_id', postId);
            field('_wpnonce', publishNonce);
            field('justbee_postcaster_post_nonce', postNonce);
            field('justbee_postcaster_publish_scope', btn.getAttribute('data-postcaster-scope') || '');
            field('justbee_postcaster_publish_network', btn.getAttribute('data-postcaster-network') || '');

            // Include all draft inputs so unsaved customizations are persisted before posting.
            Array.prototype.slice.call(compose.querySelectorAll('input[type="hidden"][name^="justbee_postcaster_post_drafts"]')).forEach(function (input) {
                field(input.name, input.value);
            });
            var disable = compose.querySelector('input[name="justbee_postcaster_post_disable_publish"]');
            if (disable && disable.checked) {
                field('justbee_postcaster_post_disable_publish', '1');
            }

            document.body.appendChild(form);
            form.submit();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var compose = document.querySelector('[data-postcaster-compose]');
        if (compose) {
            init(compose);
        }
    });
})();
