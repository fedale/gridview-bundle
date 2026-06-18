import { Controller } from '@hotwired/stimulus';
import i18n from '../i18n.js';

/**
 * Optional language switcher shipped by the bundle — use it only when the host
 * page has no switcher of its own (see the i18n `own_switcher` option). Any
 * external switcher can drive the grid instead (DOM event / <html lang> / the
 * window.GridviewI18n API), so this controller is not required.
 *
 *   #toggle  → cycle through the available locales
 *   #set     → set a specific locale (data-gridview-locale-switcher-locale-param)
 */
export default class extends Controller {
    static targets = ['label'];

    connect() {
        i18n.init();
        this._sync();
        this._onChange = () => this._sync();
        document.addEventListener('gridview:locale-changed', this._onChange);
    }

    disconnect() {
        document.removeEventListener('gridview:locale-changed', this._onChange);
    }

    set(event) {
        const loc = event.params.locale || event.currentTarget.dataset.locale;
        if (loc) i18n.setLocale(loc);
    }

    toggle() {
        const locales = i18n.locales();
        if (locales.length < 2) return;
        const idx = locales.indexOf(i18n.getLocale());
        i18n.setLocale(locales[(idx + 1) % locales.length]);
    }

    _sync() {
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = i18n.getLocale().toUpperCase();
        }
    }
}
