/*
 * payment-gateway back office behaviour (no dependencies, no network). Trimmed port of Okean's admin.js: only the
 * generic shell pieces, none of Okean's domain features (global search, problems panel, column picker, favorites,
 * list auto-refresh, charts).
 *
 * 1. Sidebar groups: any number can be open, the state is remembered in localStorage; the group that holds the
 *    current page is always open. Replaces EasyAdmin's one-open-at-a-time accordion, whose click handler is skipped.
 * 2. Sidebar search: filters menu items by label, opens matching groups while typing, Escape clears.
 * 3. Copy buttons: any [data-pg-copy] copies its value and shows a short toast.
 * 4. Flash toasts: server-rendered flashes ([data-pg-flash-toast], one per Symfony flash message) auto-dismiss after
 *    a few seconds or on their close button; distinct from the single transient "Copied" popup in initCopy() above.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'pg.admin.menu.v1';

    function readState() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);
            var parsed = raw ? JSON.parse(raw) : {};

            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function writeState(state) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {
            /* private mode or full storage: the menu just does not remember */
        }
    }

    function labelOf(item) {
        var label = item.querySelector(':scope > .ea-sidebar-item-link .ea-sidebar-item-label');

        return label ? label.textContent.trim() : '';
    }

    // EasyAdmin 5's own sidebar (app.js) already does the expand/collapse purely by toggling the 'is-expanded'
    // class (grid-template-rows animation lives in its sidebar.css) -- this just reuses that same class, so no
    // inline height bookkeeping is needed here, only which groups get it and when.
    function setOpen(group, open) {
        group.classList.toggle('is-expanded', open);
        var toggle = group.querySelector(':scope > .ea-sidebar-item-link');
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function initMenu() {
        var menu = document.getElementById('main-menu');
        if (!menu) {
            return;
        }

        var groups = Array.prototype.slice.call(menu.querySelectorAll('.ea-sidebar-item.has-submenu'));
        var saved = readState();
        var searching = false;

        groups.forEach(function (group) {
            var holdsCurrentPage = group.querySelector('.ea-sidebar-item.is-active') !== null;
            var label = labelOf(group);
            var open = holdsCurrentPage || (label in saved && saved[label] === true);
            setOpen(group, open);
        });

        // capture phase on the nav: runs before EasyAdmin's own handler on the toggle (app.js's #createMainMenu,
        // a single-open accordion), which is then never reached
        menu.addEventListener('click', function (event) {
            var toggle = event.target.closest('.ea-sidebar-item.has-submenu:not(.is-kept-open) > .ea-sidebar-item-link');
            if (!toggle || !menu.contains(toggle)) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();

            var group = toggle.parentElement;
            var open = !group.classList.contains('is-expanded');
            setOpen(group, open);
            if (!searching) {
                saved[labelOf(group)] = open;
                writeState(saved);
            }
        }, true);

        var input = document.querySelector('[data-pg-menu-search]');
        if (!input) {
            return;
        }

        var leaves = Array.prototype.slice.call(menu.querySelectorAll('.ea-sidebar-item:not(.has-submenu)'));
        var headers = Array.prototype.slice.call(menu.querySelectorAll('.ea-sidebar-group-label'));

        function restore() {
            searching = false;
            menu.classList.remove('is-filtering');
            leaves.concat(groups, headers).forEach(function (el) {
                el.hidden = false;
            });
            groups.forEach(function (group) {
                var label = labelOf(group);
                var holdsCurrentPage = group.querySelector('.ea-sidebar-item.is-active') !== null;
                setOpen(group, holdsCurrentPage || saved[label] === true);
            });
        }

        function filter() {
            var query = input.value.trim().toLowerCase();
            if (query === '') {
                restore();

                return;
            }

            searching = true;
            menu.classList.add('is-filtering');
            headers.forEach(function (header) {
                header.hidden = true;
            });

            leaves.forEach(function (leaf) {
                leaf.hidden = labelOf(leaf).toLowerCase().indexOf(query) === -1;
            });
            groups.forEach(function (group) {
                var visible = group.querySelectorAll('.ea-sidebar-item:not(.has-submenu):not([hidden])').length;
                var groupMatches = labelOf(group).toLowerCase().indexOf(query) !== -1;
                if (groupMatches) {
                    group.querySelectorAll('.ea-sidebar-item:not(.has-submenu)').forEach(function (leaf) {
                        leaf.hidden = false;
                    });
                }
                group.hidden = visible === 0 && !groupMatches;
                setOpen(group, !group.hidden);
            });
        }

        input.addEventListener('input', filter);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                input.value = '';
                filter();
            } else if (event.key === 'Enter') {
                var first = menu.querySelector('.ea-sidebar-item:not(.has-submenu):not([hidden]) a.ea-sidebar-item-link');
                if (first && input.value.trim() !== '') {
                    window.location.href = first.href;
                }
            }
        });
    }

    var toastTimer = null;

    function toast(message) {
        var el = document.querySelector('.pg-toast');
        if (!el) {
            el = document.createElement('div');
            el.className = 'pg-toast';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        el.textContent = message;
        el.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            el.classList.remove('is-visible');
        }, 1600);
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            var ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (e) {
                ok = false;
            }
            document.body.removeChild(area);
            ok ? resolve() : reject(new Error('copy failed'));
        });
    }

    function initCopy() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-pg-copy]');
            if (!button) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            copyText(button.getAttribute('data-pg-copy') || '').then(function () {
                button.classList.add('is-done');
                window.setTimeout(function () {
                    button.classList.remove('is-done');
                }, 1200);
                toast('Copied');
            }, function () {
                toast('Could not copy');
            });
        });
    }

    var FLASH_TOAST_MS = 6000;

    function dismissFlashToast(el) {
        if (!el || el.classList.contains('is-leaving')) {
            return;
        }
        el.classList.add('is-leaving');
        window.setTimeout(function () {
            el.remove();
        }, 200);
    }

    function initFlashToasts() {
        var container = document.querySelector('[data-pg-flash-toasts]');
        if (!container) {
            return;
        }

        container.querySelectorAll('[data-pg-flash-toast]').forEach(function (el) {
            window.setTimeout(function () {
                dismissFlashToast(el);
            }, FLASH_TOAST_MS);

            var close = el.querySelector('[data-pg-flash-toast-close]');
            if (close) {
                close.addEventListener('click', function () {
                    dismissFlashToast(el);
                });
            }
        });
    }

    function boot() {
        initMenu();
        initCopy();
        initFlashToasts();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
