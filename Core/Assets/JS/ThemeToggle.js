/**
 * SolWed Theme Toggle — Dark/Light mode
 * Persiste en localStorage, aplica data-theme al <html>
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'solwed-theme';
    var DARK = 'solwed-dark';
    var LIGHT = 'solwed-light';

    function getPreferred() {
        var stored = localStorage.getItem(STORAGE_KEY);
        if (stored === DARK || stored === LIGHT) return stored;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? DARK : LIGHT;
    }

    function apply(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem(STORAGE_KEY, theme);

        // Actualizar icono del toggle (Lucide recrea los SVG)
        var icon = document.getElementById('themeToggleIcon');
        if (icon) {
            icon.setAttribute('data-lucide', theme === DARK ? 'sun' : 'moon');
            // Re-renderizar Lucide icons si está disponible
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        var label = document.getElementById('themeToggleLabel');
        if (label) {
            label.textContent = theme === DARK ? 'Modo claro' : 'Modo oscuro';
        }
    }

    // Aplicar tema inmediatamente (antes de render)
    apply(getPreferred());

    // Exponer toggle global
    window.solwedToggleTheme = function () {
        var current = document.documentElement.getAttribute('data-theme');
        apply(current === DARK ? LIGHT : DARK);
    };

    // Escuchar cambios del sistema
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        if (!localStorage.getItem(STORAGE_KEY)) {
            apply(e.matches ? DARK : LIGHT);
        }
    });
})();
