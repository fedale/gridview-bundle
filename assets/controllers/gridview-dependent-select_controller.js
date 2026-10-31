import { Controller } from '@hotwired/stimulus';
import { findDecidingRow } from '../deciding-row.js';

/*
 * A select whose options load from a URL that depends on another field: the municipalities of
 * the chosen province, the provinces of the chosen region (a cascade).
 *
 *   <div class="gv-form-row" data-gv-field="municipality" data-controller="gridview-dependent-select"
 *        data-gridview-dependent-select-field-value="province"
 *        data-gridview-dependent-select-url-value="/o/municipality/lookup"
 *        data-gridview-dependent-select-param-value="filter[province]">
 *     <select data-controller="symfony--ux-autocomplete--autocomplete" …>
 *   </div>
 *
 * The deciding row is the one marked data-gv-field="<field>" nearest to this row, in the same
 * collection row first (see ../deciding-row.js). When its value changes, the choice here is emptied (which in turn empties
 * the fields depending on this one) and the select's autocomplete URL becomes
 * url?param=<value>: Symfony UX Autocomplete reloads its options from it. The server must check
 * that the record chosen belongs to the deciding one.
 */
const URL_ATTRIBUTE = 'data-symfony--ux-autocomplete--autocomplete-url-value';

export default class extends Controller {
    static values = { field: String, url: String, param: String };

    connect() {
        this.source = findDecidingRow(this.element, this.fieldValue);
        this.select = this.element.querySelector('select');
        if (!this.source || !this.select) {
            return;
        }
        this.listener = () => this.update(true);
        this.source.addEventListener('change', this.listener);
        this.update(false);
    }

    disconnect() {
        this.source?.removeEventListener('change', this.listener);
    }

    /**
     * @param {boolean} clear the deciding value changed: empty the choice made here
     */
    update(clear) {
        const parent = this.source.querySelector('select, input')?.value ?? '';
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set(this.paramValue, parent);
        const target = url.pathname + url.search;

        if (clear) {
            if (this.select.value !== '') {
                if (this.select.tomselect) {
                    // Emits "change": the fields depending on this one empty in turn.
                    this.select.tomselect.clear();
                } else {
                    this.select.value = '';
                    this.select.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
            // The options of the former parent go too: the autocomplete rebuilt for the new URL
            // starts from the native <option>s, and would offer them again.
            this.select.tomselect?.clearOptions();
            for (const option of [...this.select.options]) {
                if (option.value !== '') {
                    option.remove();
                }
            }
        }
        if (this.select.getAttribute(URL_ATTRIBUTE) !== target) {
            this.select.setAttribute(URL_ATTRIBUTE, target);
        }
    }
}
