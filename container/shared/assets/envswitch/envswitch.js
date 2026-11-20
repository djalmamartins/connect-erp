/*
 * Alternador de ambientes único (ADR 0627) — o mesmo no ERP, no Studio e no Help Desk.
 * Abre o painel "Ambientes Connect" logo abaixo do botão, fecha no clique fora, no Esc e ao
 * rolar a sidebar; setas para cima e para baixo andam pelos itens.
 */
(function () {
    'use strict';

    function setup(root) {
        var toggle = root.querySelector('.cx-envswitch__toggle');
        var panel = root.querySelector('.cx-envswitch__panel');
        if (!toggle || !panel || root.hasAttribute('data-cx-envswitch-ready')) {
            return;
        }
        root.setAttribute('data-cx-envswitch-ready', '');
        panel.setAttribute('data-environment', root.getAttribute('data-environment') || 'erp');
        document.body.appendChild(panel);

        function items() {
            return Array.prototype.slice.call(panel.querySelectorAll('.cx-envswitch__item'));
        }

        function place() {
            var rect = toggle.getBoundingClientRect();
            var width = panel.offsetWidth;
            var left = Math.min(Math.max(10, rect.left), Math.max(10, window.innerWidth - width - 10));
            var top = rect.bottom + 6;
            var room = window.innerHeight - top - 10;
            if (room < 220 && rect.top > room) {
                top = Math.max(10, rect.top - 6 - panel.offsetHeight);
            }
            panel.style.left = left + 'px';
            panel.style.top = top + 'px';
        }

        function open(focusFirst) {
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            place();
            if (focusFirst) {
                var list = items();
                var current = panel.querySelector('.cx-envswitch__item.is-current') || list[0];
                if (current) {
                    current.focus();
                }
            }
        }

        function close(returnFocus) {
            if (panel.hidden) {
                return;
            }
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            if (returnFocus) {
                toggle.focus();
            }
        }

        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (panel.hidden) {
                open(event.detail === 0);
            } else {
                close(false);
            }
        });

        toggle.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                open(true);
            }
        });

        panel.addEventListener('keydown', function (event) {
            var list = items();
            var index = list.indexOf(document.activeElement);
            if (event.key === 'Escape') {
                event.preventDefault();
                close(true);
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                var step = event.key === 'ArrowDown' ? 1 : -1;
                var next = list[(index + step + list.length) % list.length];
                if (next) {
                    next.focus();
                }
            } else if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();
                (event.key === 'Home' ? list[0] : list[list.length - 1]).focus();
            } else if (event.key === 'Tab') {
                close(false);
            }
        });

        document.addEventListener('click', function (event) {
            if (!panel.contains(event.target) && !toggle.contains(event.target)) {
                close(false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.hidden) {
                close(true);
            }
        });
        window.addEventListener('resize', function () {
            if (!panel.hidden) {
                place();
            }
        });
        var scroller = toggle.closest('aside, nav, .erp-sidebar, .studio-sidebar');
        if (scroller) {
            scroller.addEventListener('scroll', function () { close(false); }, { passive: true });
        }
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-cx-envswitch]'), setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
