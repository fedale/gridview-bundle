import { Controller } from '@hotwired/stimulus';

/*
 * Shows a form row only while another field of the same form holds some values: the hours of
 * a qualification while its kind is "course".
 *
 *   <div class="gv-form-row" data-gv-field="hours" data-controller="gridview-conditional"
 *        data-gridview-conditional-field-value="kind"
 *        data-gridview-conditional-values-value='["course"]'>…</div>
 *
 * The deciding field is the row marked data-gv-field="kind" among the siblings of this row (so
 * that each row of a collection follows its own "kind"), or else anywhere in the form. A
 * select gives its value, a radio group its checked value, a checkbox "true" or "false".
 * Hiding is presentation only: the server must ignore or empty a field that does not apply.
 */
export default class extends Controller {
    static values = { field: String, values: Array };

    connect() {
        const selector = `[data-gv-field="${CSS.escape(this.fieldValue)}"]`;
        this.source = this.element.parentElement?.querySelector(`:scope > ${selector}`)
            ?? this.element.closest('form')?.querySelector(selector);
        if (!this.source) {
            return;
        }
        this.listener = () => this.update();
        this.source.addEventListener('change', this.listener);
        this.source.addEventListener('input', this.listener);
        this.update();
    }

    disconnect() {
        this.source?.removeEventListener('change', this.listener);
        this.source?.removeEventListener('input', this.listener);
    }

    update() {
        this.element.hidden = !this.valuesValue.includes(this.current());
    }

    current() {
        const checked = this.source.querySelector('input[type=radio]:checked');
        if (checked) {
            return checked.value;
        }
        const checkbox = this.source.querySelector('input[type=checkbox]');
        if (checkbox) {
            return checkbox.checked ? 'true' : 'false';
        }

        return this.source.querySelector('select, input, textarea')?.value ?? '';
    }
}
