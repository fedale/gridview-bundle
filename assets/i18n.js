/**
 * Headless i18n runtime for the gridview.
 *
 * The full catalog (every enabled locale) is shipped to the browser inside a
 * <script id="gridview-i18n-catalog"> blob, so switching language is instant —
 * no server roundtrip. The runtime is the single source of locale state and can
 * be driven by:
 *   - the bundle's own switcher,
 *   - ANY external switcher via a DOM event (configurable name),
 *   - a direct call to window.GridviewI18n.setLocale(),
 *   - observing <html lang> changes.
 *
 * DOM contract:
 *   [data-gv-i18n="key"]              → element.textContent = catalog[key]
 *   [data-gv-i18n-attr-title="key"]   → element.title = catalog[key]
 *   (also: placeholder, aria-label)
 */

const ATTRS = ['title', 'placeholder', 'aria-label'];
const LS_KEY = 'gv-locale';

const state = {
    loaded: false,
    inited: false,
    catalog: {},
    config: null,
    locale: null,
};

function defaultConfig() {
    return {
        default: 'en',
        locales: [],
        event: 'gridview:set-locale',
        eventKey: 'locale',
        observeHtmlLang: true,
        cookie: 'gv_locale',
        persistExternal: true,
    };
}

function load() {
    if (state.loaded) return;
    state.loaded = true;

    const el = document.getElementById('gridview-i18n-catalog');
    if (!el) {
        state.config = defaultConfig();
        return;
    }
    try { state.catalog = JSON.parse(el.textContent || '{}'); } catch (_) { state.catalog = {}; }
    try { state.config = Object.assign(defaultConfig(), JSON.parse(el.dataset.gvConfig || '{}')); }
    catch (_) { state.config = defaultConfig(); }
}

function knownLocales() {
    const cfg = state.config || defaultConfig();
    return (cfg.locales && cfg.locales.length) ? cfg.locales : Object.keys(state.catalog || {});
}

/** Normalize 'it-IT'/'en_US' → 'it'/'en'; return null when not in the catalog. */
function normalize(loc) {
    if (!loc) return null;
    const base = String(loc).toLowerCase().split(/[-_]/)[0];
    return knownLocales().includes(base) ? base : null;
}

function readCookie(name) {
    const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.*+?^${}()|[\]\\])/g, '\\$1') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
}

function writeCookie(name, value) {
    const oneYear = 60 * 60 * 24 * 365;
    document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${oneYear}; SameSite=Lax`;
}

const i18n = {
    getLocale() {
        load();
        if (state.locale) return state.locale;
        const cfg = state.config;
        const htmlLang = normalize(document.documentElement.lang);
        // When the host drives the locale (observeHtmlLang), the server-rendered
        // <html lang> is authoritative and must win over any previously persisted
        // choice — otherwise a stale localStorage/cookie value keeps forcing the
        // old language after the host switches. When html-lang is not observed the
        // grid owns its own locale, so the persisted choice takes precedence.
        state.locale =
            (cfg.observeHtmlLang ? htmlLang : null) ||
            normalize(safeLs()) ||
            normalize(readCookie(cfg.cookie)) ||
            htmlLang ||
            cfg.default ||
            'en';
        return state.locale;
    },

    locales() {
        load();
        return knownLocales();
    },

    /** Translate a key (with %param% interpolation), falling back to the default locale, then the key. */
    t(key, params = {}) {
        load();
        const loc = this.getLocale();
        const cat = state.catalog[loc] || {};
        let str = (key in cat)
            ? cat[key]
            : ((state.catalog[state.config.default] || {})[key] ?? key);
        for (const k of Object.keys(params)) {
            str = str.split('%' + k + '%').join(params[k]);
        }
        return str;
    },

    /** Rewrite every tagged node under `root` to the current locale. */
    apply(root = document) {
        load();
        const cat = state.catalog[this.getLocale()] || {};
        const scope = (root && root.querySelectorAll) ? root : document;

        scope.querySelectorAll('[data-gv-i18n]').forEach((el) => {
            const key = el.getAttribute('data-gv-i18n');
            if (key in cat) el.textContent = cat[key];
        });

        ATTRS.forEach((attr) => {
            scope.querySelectorAll(`[data-gv-i18n-attr-${attr}]`).forEach((el) => {
                const key = el.getAttribute(`data-gv-i18n-attr-${attr}`);
                if (key in cat) el.setAttribute(attr, cat[key]);
            });
        });
    },

    /**
     * Set the active locale and re-render. Unknown locales are a no-op (keeps
     * the current language). `persist` writes localStorage + cookie so the
     * server's next full render matches.
     */
    setLocale(loc, { persist = true, apply = true } = {}) {
        load();
        const norm = normalize(loc);
        if (!norm || norm === state.locale) {
            if (norm && apply) this.apply(document);
            return !!norm;
        }
        state.locale = norm;
        if (persist) {
            try { localStorage.setItem(LS_KEY, norm); } catch (_) { /* private mode */ }
            writeCookie(state.config.cookie, norm);
        }
        if (apply) this.apply(document);
        document.dispatchEvent(new CustomEvent('gridview:locale-changed', { detail: { locale: norm } }));
        return true;
    },

    /** Wire external switchers (idempotent). Called by the gridview-i18n controller. */
    init() {
        load();
        if (state.inited) return;
        state.inited = true;

        const cfg = state.config;
        this.getLocale(); // resolve once

        if (cfg.event) {
            document.addEventListener(cfg.event, (e) => {
                const loc = (e && e.detail && (e.detail[cfg.eventKey] ?? e.detail.locale));
                if (loc) this.setLocale(loc, { persist: cfg.persistExternal !== false });
            });
        }

        if (cfg.observeHtmlLang && document.documentElement) {
            new MutationObserver(() => {
                const loc = document.documentElement.lang;
                if (loc) this.setLocale(loc, { persist: cfg.persistExternal !== false });
            }).observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] });
        }
    },
};

function safeLs() {
    try { return localStorage.getItem(LS_KEY); } catch (_) { return null; }
}

if (typeof window !== 'undefined') {
    window.GridviewI18n = i18n;
}

export default i18n;
