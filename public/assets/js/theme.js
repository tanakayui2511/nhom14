/*
 * Light/dark theme and accent color for ReleaseTracker (no libraries).
 * The inline script in <head> already applied both before CSS loaded;
 * this file keeps the buttons in sync and handles changes.
 * Keys: localStorage "rt-theme" (light|dark), "rt-accent" (indigo|ocean|forest|sunset|rose).
 */
(function () {
    'use strict';

    var ACCENTS = ['indigo', 'ocean', 'forest', 'sunset', 'rose'];
    var root = document.documentElement;
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var fadeTimer = null;

    // localStorage can throw (private mode, blocked site data).
    function read(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }
    function write(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* still applies for this view */ }
    }
    function each(selector, fn) {
        Array.prototype.forEach.call(document.querySelectorAll(selector), fn);
    }
    function fade() {
        root.classList.add('theme-switching');
        window.clearTimeout(fadeTimer);
        fadeTimer = window.setTimeout(function () { root.classList.remove('theme-switching'); }, 300);
    }

    function storedTheme() {
        var t = read('rt-theme');
        return t === 'light' || t === 'dark' ? t : null;
    }
    function systemTheme() {
        return media && media.matches ? 'dark' : 'light';
    }

    function applyTheme(theme, animate) {
        if (animate) { fade(); }
        root.setAttribute('data-theme', theme);
        var dark = theme === 'dark';
        each('[data-theme-toggle]', function (button) {
            button.setAttribute('aria-pressed', dark ? 'true' : 'false');
            button.setAttribute('aria-label', dark ? 'Chuyển sang chế độ sáng' : 'Chuyển sang chế độ tối');
            button.setAttribute('title', dark ? 'Chế độ sáng' : 'Chế độ tối');
        });
        each('[data-theme-label]', function (label) { label.textContent = dark ? 'Sáng' : 'Tối'; });
    }

    function applyAccent(accent, animate) {
        if (ACCENTS.indexOf(accent) === -1) { accent = 'indigo'; }
        if (animate) { fade(); }
        root.setAttribute('data-accent', accent);
        each('[data-accent-option]', function (button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-accent-option') === accent ? 'true' : 'false');
        });
    }

    applyTheme(storedTheme() || systemTheme(), false);
    applyAccent(read('rt-accent'), false);

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) { return; }
        if (target.closest('[data-theme-toggle]')) {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            write('rt-theme', next);
            applyTheme(next, true);
            return;
        }
        var option = target.closest('[data-accent-option]');
        if (option) {
            var accent = option.getAttribute('data-accent-option');
            write('rt-accent', accent);
            applyAccent(accent, true);
        }
    });

    // Arrow keys / Home / End move between accent swatches.
    document.addEventListener('keydown', function (event) {
        var option = event.target && event.target.closest ? event.target.closest('[data-accent-option]') : null;
        if (!option) { return; }
        var options = Array.prototype.slice.call(option.parentNode.querySelectorAll('[data-accent-option]'));
        var i = options.indexOf(option);
        var n = -1;
        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') { n = (i + 1) % options.length; }
        else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') { n = (i - 1 + options.length) % options.length; }
        else if (event.key === 'Home') { n = 0; }
        else if (event.key === 'End') { n = options.length - 1; }
        if (n !== -1) {
            event.preventDefault();
            options[n].focus();
        }
    });

    // Follow the OS only while the user has not chosen a theme.
    if (media) {
        var onChange = function () { if (storedTheme() === null) { applyTheme(systemTheme(), true); } };
        if (media.addEventListener) { media.addEventListener('change', onChange); } else if (media.addListener) { media.addListener(onChange); }
    }

    // Keep other open tabs in sync.
    window.addEventListener('storage', function (event) {
        if (event.key === 'rt-theme') { applyTheme(storedTheme() || systemTheme(), true); }
        if (event.key === 'rt-accent') { applyAccent(event.newValue, true); }
    });
})();
