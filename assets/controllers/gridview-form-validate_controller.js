import { Controller } from '@hotwired/stimulus';

/**
 * Live client-side validation for generated CRUD forms (progressive enhancement
 * over the authoritative server-side validation):
 *  - required/format via the HTML5 Constraint Validation API, shown inline;
 *  - uniqueness via a debounced fetch to checkUrl (excludes the current row in
 *    edit through the `id` value).
 *
 * Wired by crud/_form_layout.html.twig when a `validate` context is provided.
 */
export default class extends Controller {
    static values = {
        checkUrl: String,
        unique: Array,
        id: String,
        name: { type: String, default: 'gridform' },
    };

    connect() {
        this._timers = new Map();
        this._onInput = (e) => this._handleInput(e.target);
        this._onBlur = (e) => this._handleBlur(e.target);
        this._targets().forEach((el) => {
            el.addEventListener('input', this._onInput);
            el.addEventListener('blur', this._onBlur);
        });
    }

    disconnect() {
        this._targets().forEach((el) => {
            el.removeEventListener('input', this._onInput);
            el.removeEventListener('blur', this._onBlur);
        });
        this._timers.forEach((t) => clearTimeout(t));
    }

    _handleInput(el) {
        // Don't nag with "required" while typing; do surface format errors.
        this._validateNative(el, false);
        const field = this._fieldName(el);
        if (field && this.uniqueValue.includes(field)) {
            this._scheduleUnique(el, field);
        }
    }

    _handleBlur(el) {
        this._validateNative(el, true);
        const field = this._fieldName(el);
        if (field && this.uniqueValue.includes(field)) {
            this._checkUnique(el, field);
        }
    }

    _validateNative(el, showRequired) {
        if (el.checkValidity()) {
            if (el.dataset.gvUniqueError !== '1') this._clear(el);
            return;
        }
        // While typing, ignore the "value missing" state to avoid nagging.
        if (!showRequired && el.validity.valueMissing) {
            this._clear(el);
            return;
        }
        this._show(el, el.validationMessage);
    }

    _scheduleUnique(el, field) {
        clearTimeout(this._timers.get(el));
        this._timers.set(el, setTimeout(() => this._checkUnique(el, field), 400));
    }

    _checkUnique(el, field) {
        const value = el.value.trim();
        if (value === '') {
            delete el.dataset.gvUniqueError;
            return;
        }
        const params = new URLSearchParams({ field, value });
        if (this.idValue) params.set('id', this.idValue);

        fetch(`${this.checkUrlValue}?${params.toString()}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((data) => {
                if (data && data.exists) {
                    el.dataset.gvUniqueError = '1';
                    this._show(el, 'Valore già in uso.');
                } else {
                    delete el.dataset.gvUniqueError;
                    if (el.checkValidity()) this._clear(el);
                }
            })
            .catch(() => { /* ignore network errors; server still validates */ });
    }

    _targets() {
        return [...this.element.querySelectorAll('input, select, textarea')]
            .filter((el) => el.name && !['submit', 'button', 'hidden'].includes(el.type));
    }

    _fieldName(el) {
        const m = el.name.match(/\[([^\]]+)\]$/);
        return m ? m[1] : el.name;
    }

    _show(el, message) {
        el.classList.add('is-invalid');
        let feedback = el.parentElement.querySelector(':scope > .gv-validate-msg');
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.className = 'gv-validate-msg';
            el.parentElement.appendChild(feedback);
        }
        feedback.textContent = message;
    }

    _clear(el) {
        el.classList.remove('is-invalid');
        el.parentElement.querySelector(':scope > .gv-validate-msg')?.remove();
    }
}
