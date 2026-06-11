(function () {
    'use strict';

    function toArray(list) {
        return Array.prototype.slice.call(list || []);
    }

    function syncToggleRows(scope) {
        var controls = toArray(scope.querySelectorAll('[data-postcaster-toggle-control]'));

        controls.forEach(function (control) {
            var key = control.getAttribute('data-postcaster-toggle-control');
            var rows = toArray(scope.querySelectorAll('[data-postcaster-toggle-row="' + key + '"]'));

            if (!rows.length) {
                return;
            }

            var sync = function () {
                rows.forEach(function (row) {
                    if (row.hasAttribute('data-postcaster-template-row')) {
                        row.style.display = '';
                        return;
                    }

                    row.style.display = control.checked ? '' : 'none';
                });
            };

            control.addEventListener('change', sync);
            sync();
        });
    }

    function initTabs(root) {
        var tabAttribute = root.getAttribute('data-postcaster-tab-attribute') || 'postcaster-tab';
        var panelAttribute = root.getAttribute('data-postcaster-panel-attribute') || 'postcaster-panel';
        var storageSuffix = root.getAttribute('data-postcaster-tab-storage') || tabAttribute;
        var tabs = toArray(root.querySelectorAll('[data-' + tabAttribute + ']'));
        var panels = toArray(root.querySelectorAll('[data-' + panelAttribute + ']'));
        var storageKey = 'postcaster-tab:' + storageSuffix;

        if (!tabs.length || !panels.length) {
            return;
        }

        function activateTab(key) {
            tabs.forEach(function (tab) {
                var isActive = tab.getAttribute('data-' + tabAttribute) === key;
                tab.classList.toggle('nav-tab-active', isActive);
            });

            panels.forEach(function (panel) {
                var isActive = panel.getAttribute('data-' + panelAttribute) === key;
                panel.classList.toggle('is-active', isActive);
            });

            try {
                window.localStorage.setItem(storageKey, key);
            } catch (error) {
            }
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                activateTab(tab.getAttribute('data-' + tabAttribute));
            });
        });

        try {
            var savedKey = window.localStorage.getItem(storageKey);
            if (savedKey && tabs.some(function (tab) { return tab.getAttribute('data-' + tabAttribute) === savedKey; })) {
                activateTab(savedKey);
                return;
            }
        } catch (error) {
        }

        var initialTab = tabs.find(function (tab) {
            return tab.classList.contains('nav-tab-active');
        }) || tabs[0];

        if (initialTab) {
            activateTab(initialTab.getAttribute('data-' + tabAttribute));
        }
    }

    function initPersonalNetworksToggle(scope) {
        var toggle = scope.querySelector('[data-postcaster-personal-networks-toggle]');
        var list = scope.querySelector('[data-postcaster-personal-networks-list]');

        if (!toggle || !list) {
            return;
        }

        var sync = function () {
            if (toggle.checked) {
                list.hidden = false;
                list.style.display = '';
            } else {
                list.hidden = true;
                list.style.display = 'none';
            }
        };

        toggle.addEventListener('change', sync);
        sync();
    }

    function initProfileTestButtons(scope) {
        var nonceField = scope.querySelector('input[name="justbee_postcaster_profile_test_post_nonce"]');
        var buttons = toArray(scope.querySelectorAll('[data-postcaster-profile-test-button]'));

        if (!nonceField || !buttons.length) {
            return;
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var form = document.createElement('form');
                form.method = 'post';
                form.action = button.getAttribute('data-postcaster-action-url') || '';
                form.style.display = 'none';

                var fields = {
                    action: 'justbee_postcaster_send_profile_test',
                    network: button.getAttribute('data-postcaster-network') || '',
                    user_id: button.getAttribute('data-postcaster-user-id') || '',
                    justbee_postcaster_profile_test_post_nonce: nonceField.value
                };

                Object.keys(fields).forEach(function (name) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = fields[name];
                    form.appendChild(input);
                });

                document.body.appendChild(form);
                form.submit();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        toArray(document.querySelectorAll('[data-postcaster-tabs-root]')).forEach(initTabs);
        syncToggleRows(document);
        initPersonalNetworksToggle(document);
        initProfileTestButtons(document);
    });
}());
