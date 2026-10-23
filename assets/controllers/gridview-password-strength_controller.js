import { Controller } from '@hotwired/stimulus';

/*
 * The strength meter of a password input: four bars and a word under it, updated while typing.
 *
 *   <input type="password" data-controller="gridview-password-strength"
 *          data-action="gridview-password-strength#update"
 *          data-gridview-password-strength-min-length-value="8"
 *          data-gridview-password-strength-labels-value='["Too short","Weak","Fair","Good","Strong"]'>
 *
 * A hint only, computed in the browser: below min-length it is level 0; then every kind of
 * character beyond the first (lower case, upper case, digit, symbol) and every 4 characters
 * beyond min-length add a level, up to 4. The server's rules decide what is accepted.
 * Styled by .gv-password-meter (_forms.scss).
 */
export default class extends Controller {
    static values = {
        minLength: { type: Number, default: 8 },
        labels: { type: Array, default: ['Too short', 'Weak', 'Fair', 'Good', 'Strong'] },
    };

    connect() {
        this.meter = document.createElement('div');
        this.meter.className = 'gv-password-meter';
        this.meter.innerHTML = '<span class="gv-password-meter__bar"></span>'.repeat(4)
            + '<small class="gv-password-meter__label" aria-live="polite"></small>';
        this.element.insertAdjacentElement('afterend', this.meter);
        this.update();
    }

    disconnect() {
        this.meter?.remove();
    }

    update() {
        const level = this.level(this.element.value);
        this.meter.dataset.level = String(level ?? 0);
        this.meter.hidden = null === level;
        this.meter.querySelector('.gv-password-meter__label').textContent = null === level ? '' : (this.labelsValue[level] ?? '');
    }

    /**
     * 0 (shorter than the minimum) to 4; null for an empty input.
     */
    level(password) {
        if ('' === password) {
            return null;
        }
        const length = [...password].length;
        if (length < this.minLengthValue) {
            return 0;
        }
        const kinds = [/\p{Ll}/u, /\p{Lu}/u, /\d/u, /[^\p{L}\p{N}]/u].filter((kind) => kind.test(password)).length;
        const score = (kinds - 1) + Math.floor((length - this.minLengthValue) / 4);

        return Math.max(1, Math.min(4, score));
    }
}
