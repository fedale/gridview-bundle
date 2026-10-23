import { Controller } from '@hotwired/stimulus';

/*
 * The show/hide eye of a password input: a button inside the input switches it between hidden
 * and clear text.
 *
 *   <input type="password" data-controller="gridview-password-reveal"
 *          data-gridview-password-reveal-show-label-value="Show password"
 *          data-gridview-password-reveal-hide-label-value="Hide password">
 *
 * The input is never moved (that would disconnect the controller): its row becomes a
 * gv-form-row--password grid that lays the .gv-password-reveal button over it (_forms.scss).
 */
const EYE = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7a9.9 9.9 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

export default class extends Controller {
    static values = {
        showLabel: { type: String, default: 'Show password' },
        hideLabel: { type: String, default: 'Hide password' },
    };

    connect() {
        this.element.parentElement.classList.add('gv-form-row--password');
        this.button = document.createElement('button');
        this.button.type = 'button';
        this.button.className = 'gv-password-reveal';
        this.button.disabled = this.element.disabled;
        this.button.addEventListener('click', () => this.toggle());
        this.element.insertAdjacentElement('afterend', this.button);
        this.render(false);
    }

    disconnect() {
        // Turbo may cache the page: never keep a password shown in clear.
        this.element.type = 'password';
        this.button?.remove();
    }

    toggle() {
        this.render('password' === this.element.type);
        this.element.focus();
    }

    render(shown) {
        this.element.type = shown ? 'text' : 'password';
        this.button.innerHTML = shown ? EYE_OFF : EYE;
        this.button.setAttribute('aria-label', shown ? this.hideLabelValue : this.showLabelValue);
        this.button.setAttribute('aria-pressed', String(shown));
    }
}
