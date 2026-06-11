/**
 * PostCaster shared modal helper.
 *
 * Wires <dialog> open/close behaviour via data attributes:
 *   - data-postcaster-modal-open="<dialog-id>"   on a button
 *   - data-postcaster-modal-close="<dialog-id>"  on a button
 *
 * Optional: data-postcaster-modal-snapshot-textarea="<textarea-selector>"
 * on a button captures the value when the dialog opens, so the
 * data-postcaster-modal-restore="<textarea-selector>" cancel button can put
 * it back. Other listeners (input, change) are dispatched after restore so
 * synced state catches up.
 *
 * Optional: data-postcaster-modal-hide-on-open="<selector>" hides matching
 * elements when the dialog opens (e.g. to reset stale "preview updated"
 * notices from a previous editing session).
 *
 * The page may still wire its own listeners — these helpers only own the
 * generic open/close/restore plumbing, not state-specific behaviour.
 */
(function () {
    'use strict';

    var snapshots = {};
    var previewSnapshots = {};

    function getDialog(id) {
        if (!id) {
            return null;
        }
        var el = document.getElementById(id);
        return el && el.tagName === 'DIALOG' ? el : null;
    }

    function openDialog(dialog) {
        if (!dialog) {
            return;
        }
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
    }

    function closeDialog(dialog) {
        if (!dialog) {
            return;
        }
        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
    }

    function dispatch(target, eventName) {
        if (!target) {
            return;
        }
        target.dispatchEvent(new Event(eventName, { bubbles: true }));
    }

    function snapshotPreview(selector) {
        previewSnapshots[selector] = Array.prototype.slice
            .call(document.querySelectorAll(selector))
            .map(function (el) { return el.innerHTML; });
    }

    function restorePreview(selector) {
        if (!Object.prototype.hasOwnProperty.call(previewSnapshots, selector)) {
            return;
        }
        var stored = previewSnapshots[selector];
        Array.prototype.slice
            .call(document.querySelectorAll(selector))
            .forEach(function (el, idx) {
                if (idx < stored.length) {
                    el.innerHTML = stored[idx];
                }
            });
    }

    document.addEventListener('click', function (event) {
        var openTrigger = event.target.closest('[data-postcaster-modal-open]');
        if (openTrigger) {
            event.preventDefault();
            var openId = openTrigger.getAttribute('data-postcaster-modal-open');
            var snapshotKey = openTrigger.getAttribute('data-postcaster-modal-snapshot-textarea');
            if (snapshotKey) {
                var sourceTextarea = document.querySelector(snapshotKey);
                if (sourceTextarea) {
                    snapshots[snapshotKey] = sourceTextarea.value;
                }
            }
            var snapshotPreviewKey = openTrigger.getAttribute('data-postcaster-modal-snapshot-preview');
            if (snapshotPreviewKey) {
                snapshotPreview(snapshotPreviewKey);
            }
            var hideOnOpenSelector = openTrigger.getAttribute('data-postcaster-modal-hide-on-open');
            if (hideOnOpenSelector) {
                Array.prototype.slice.call(document.querySelectorAll(hideOnOpenSelector)).forEach(function (el) {
                    el.hidden = true;
                    el.style.display = 'none';
                });
            }
            openDialog(getDialog(openId));
            return;
        }

        var closeTrigger = event.target.closest('[data-postcaster-modal-close]');
        if (closeTrigger) {
            event.preventDefault();
            var closeId = closeTrigger.getAttribute('data-postcaster-modal-close');
            var restoreKey = closeTrigger.getAttribute('data-postcaster-modal-restore');
            if (restoreKey && Object.prototype.hasOwnProperty.call(snapshots, restoreKey)) {
                var targetTextarea = document.querySelector(restoreKey);
                if (targetTextarea) {
                    targetTextarea.value = snapshots[restoreKey];
                    targetTextarea.setAttribute('data-postcaster-current-template', targetTextarea.value || '');
                    dispatch(targetTextarea, 'input');
                    dispatch(targetTextarea, 'change');
                }
            }
            var restorePreviewKey = closeTrigger.getAttribute('data-postcaster-modal-restore-preview');
            if (restorePreviewKey) {
                restorePreview(restorePreviewKey);
            }
            closeDialog(getDialog(closeId));
        }
    });

    window.PostCasterModal = {
        open: function (id) { openDialog(getDialog(id)); },
        close: function (id) { closeDialog(getDialog(id)); },
        snapshot: function (key, value) { snapshots[key] = value; },
        getSnapshot: function (key) {
            return Object.prototype.hasOwnProperty.call(snapshots, key) ? snapshots[key] : null;
        }
    };
})();
