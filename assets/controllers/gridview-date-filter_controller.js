import { Controller } from '@hotwired/stimulus';
import flatpickr from 'flatpickr';

/**
 * The flatpickr locale code for an explicit `locale` client option, else for
 * the page language (<html lang="it-IT"> → 'it'). Null means flatpickr's
 * built-in English.
 */
function localeCode(explicit) {
    const code = String(explicit || document.documentElement.lang || '').toLowerCase().split('-')[0];

    return /^[a-z]{2,3}$/.test(code) && code !== 'en' ? code : null;
}

/**
 * Loads flatpickr's locale module on demand rather than with a static import:
 * a static import of a locale the host's importmap doesn't map fails the whole
 * controller, and the date filter silently degrades to a plain input. A host
 * maps the locales it uses (`importmap:require flatpickr/dist/l10n/it.js`);
 * a missing one leaves the picker in English, with a warning.
 */
async function loadLocale(code) {
    try {
        const module = await import(`flatpickr/dist/l10n/${code}.js`);
        // The ESM build exports the locale by name (`Italian`) and every
        // registered locale under `default`; a CDN conversion may nest them.
        const candidates = [module.default?.[code], module.default?.default?.[code], ...Object.values(module)];

        return candidates.find((locale) => locale && typeof locale === 'object' && 'weekdays' in locale) ?? null;
    } catch (error) {
        console.warn(`gridview-date-filter: flatpickr locale "${code}" could not be loaded, the picker stays in English. Map it with "importmap:require flatpickr/dist/l10n/${code}.js".`, error);

        return null;
    }
}

export default class extends Controller {
    static values = {
        options: { type: Object, default: {} },
    };

    connect() {
        this._fromInput = this.element.querySelector('input[name$="[from]"]');
        this._toInput   = this.element.querySelector('input[name$="[to]"]');

        if (!this._fromInput) return;

        const opts   = Object.assign(
            { mode: 'range', altFormat: 'd/m/Y' },
            this.optionsValue
        );
        const code   = localeCode(opts.locale);
        delete opts.locale;
        const mode   = opts.mode;

        // minDate/maxDate arrive as ISO (Y-m-d) from the server, but flatpickr's
        // dateFormat here is d/m/Y — parsing the ISO string against that format
        // would misread it. Convert to Date objects (format-independent) first.
        const isoToDate = (s) =>
            (typeof s === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(s))
                ? new Date(s + 'T00:00:00')
                : s;
        if (opts.minDate) opts.minDate = isoToDate(opts.minDate);
        if (opts.maxDate) opts.maxDate = isoToDate(opts.maxDate);

        // Read Symfony input values before anything else touches them
        const fromVal = this._fromInput.value;
        const toVal   = this._toInput?.value ?? '';

        // Create a dedicated display input for Flatpickr.
        // Flatpickr manages ONLY this element — the Symfony from/to inputs stay clean.
        this._displayInput = document.createElement('input');
        this._displayInput.type = 'text';
        this._displayInput.className = 'gv-date-display';

        // Prepend is always safe: it inserts as first child regardless of DOM nesting
        this.element.prepend(this._displayInput);

        // Hide every other direct child of the element (the Symfony form sub-field wrappers)
        this._symfonyChildren = Array.from(this.element.children)
            .filter(el => el !== this._displayInput);
        this._symfonyChildren.forEach(el => { el.style.display = 'none'; });

        // Block display input events from bubbling to gridview-filter action
        this._blockBubble = (e) => e.stopPropagation();
        this._displayInput.addEventListener('input',  this._blockBubble);
        this._displayInput.addEventListener('change', this._blockBubble);

        // Parse ISO strings as Date objects to avoid dateFormat mismatch in Flatpickr
        const defaultDate = this._parseDefaultDate(mode, fromVal, toVal);

        this._fp = flatpickr(this._displayInput, {
            ...opts,
            mode,
            defaultDate,
            altInput:   false,                     // display input is the only visible one
            dateFormat: opts.altFormat ?? 'd/m/Y', // user-facing format
            onChange: (dates) => this._onDateChange(dates, mode),
        });

        // The picker works in English at once; the locale is swapped in when
        // its module arrives, unless the controller was disconnected meanwhile.
        if (code !== null) {
            const fp = this._fp;
            loadLocale(code).then((locale) => {
                if (locale && this._fp === fp) {
                    fp.set('locale', locale);
                }
            });
        }
    }

    disconnect() {
        this._displayInput?.removeEventListener('input',  this._blockBubble);
        this._displayInput?.removeEventListener('change', this._blockBubble);
        this._fp?.destroy();
        this._fp = null;
        this._displayInput?.remove();
        this._displayInput = null;
        this._symfonyChildren?.forEach(el => { el.style.display = ''; });
        this._symfonyChildren = null;
    }

    clearDate() {
        this._fp?.clear();
        this._setValues('', '');
        this._dispatchInput();
    }

    // ── Private ───────────────────────────────────────────────────────

    _onDateChange(dates, mode) {
        if (mode === 'single') {
            this._setValues(dates[0] ? this._fmt(dates[0]) : '', '');
            if (dates[0]) this._dispatchInput();
        } else {
            if (dates.length === 2) {
                this._setValues(this._fmt(dates[0]), this._fmt(dates[1]));
                this._dispatchInput();
            } else if (dates.length === 0) {
                this._setValues('', '');
                this._dispatchInput();
            }
            // dates.length === 1 → first date selected, wait for second (no submit)
        }
    }

    _fmt(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    _setValues(from, to) {
        if (this._fromInput) this._fromInput.value = from;
        if (this._toInput)   this._toInput.value   = to;
    }

    _dispatchInput() {
        this.element.dispatchEvent(new Event('input', { bubbles: true }));
    }

    _parseDefaultDate(mode, from, to) {
        if (!from) return null;
        // T00:00:00 forces local-time interpretation (not UTC)
        const parseISO = (s) => s ? new Date(s + 'T00:00:00') : null;
        if (mode === 'single') return [parseISO(from)];
        const f = parseISO(from);
        const t = parseISO(to);
        if (f && t) return [f, t];
        if (f)      return [f];
        return null;
    }
}
