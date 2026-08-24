/**
 * Applies the admin-configured brand palette at runtime by overriding the
 * CSS custom properties theme.css defines on :root. Same "one JS call
 * beats server-side dynamic CSS generation" approach as the reference
 * plugin's aqniet-blue.js.
 */
(function () {
    'use strict';
    function applyTheme() {
        if (typeof window.ZAASTheme === 'undefined' || !window.ZAASTheme.enabled) { return; }
        var t = window.ZAASTheme;
        var root = document.documentElement;
        var hex = /^#[0-9a-f]{6}$/i;
        if (hex.test(t.accent)) { root.style.setProperty('--zaas-accent', t.accent); }
        if (hex.test(t.accentDark)) { root.style.setProperty('--zaas-accent-dark', t.accentDark); }
        if (hex.test(t.surface)) { root.style.setProperty('--zaas-surface', t.surface); }
        if (hex.test(t.text)) { root.style.setProperty('--zaas-text', t.text); }
        if (typeof t.radius === 'number') { root.style.setProperty('--zaas-radius', t.radius + 'px'); }
    }

    applyTheme();
    document.addEventListener('DOMContentLoaded', applyTheme);
    // Re-apply inside the Elementor editor preview iframe when a widget is (re)rendered.
    if (window.elementorFrontend && window.elementorFrontend.hooks) {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/global', applyTheme);
    }
})();
