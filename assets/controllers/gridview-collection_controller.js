import { Controller } from '@hotwired/stimulus';

/*
 * Repeatable rows of a form, built on a Symfony CollectionType (allow_add / allow_delete).
 *
 *   <div class="gv-collection" data-controller="gridview-collection"
 *        data-gridview-collection-prototype-value="{{ row prototype HTML }}"
 *        data-gridview-collection-prototype-name-value="__name__"
 *        data-gridview-collection-index-value="{{ next free key }}"
 *        data-gridview-collection-min-value="1" data-gridview-collection-max-value="0">
 *     <div class="gv-collection__rows" data-gridview-collection-target="list">
 *       <fieldset class="gv-collection__row" data-gridview-collection-target="row">
 *         … <button type="button" class="gv-collection__remove" data-action="gridview-collection#remove">
 *       </fieldset>
 *     </div>
 *     <button type="button" class="gv-btn gv-collection__add" data-gridview-collection-target="add"
 *             data-action="gridview-collection#add">+ Add</button>
 *   </div>
 *
 * "Add" appends the prototype, its placeholder replaced by a key no row uses; "remove" drops a
 * row. min and max (0: no limit) hide "remove" and disable "add"; the server checks both again.
 * The rows of a nested collection belong to their own controller.
 */
export default class extends Controller {
    static targets = ['list', 'row', 'add'];
    static values = {
        prototype: String,
        prototypeName: { type: String, default: '__name__' },
        index: Number,
        min: Number,
        max: Number,
    };

    connect() {
        this.update();
    }

    add() {
        if (this.maxValue > 0 && this.rowTargets.length >= this.maxValue) {
            return;
        }
        const html = this.prototypeValue.replaceAll(this.prototypeNameValue, String(this.indexValue));
        this.indexValue += 1;
        this.listTarget.insertAdjacentHTML('beforeend', html);
        this.update();
        this.rowTargets[this.rowTargets.length - 1].querySelector('input:not([type=hidden]), select, textarea')?.focus();
    }

    remove(event) {
        if (this.rowTargets.length <= this.minValue) {
            return;
        }
        event.target.closest('[data-gridview-collection-target~="row"]').remove();
        this.update();
    }

    update() {
        const rows = this.rowTargets.length;
        if (this.hasAddTarget) {
            this.addTarget.disabled = this.maxValue > 0 && rows >= this.maxValue;
        }
        for (const row of this.rowTargets) {
            // Only this row's own button, not those of a collection nested in it.
            for (const button of row.querySelectorAll('.gv-collection__remove')) {
                if (button.closest('[data-gridview-collection-target~="row"]') === row) {
                    button.hidden = rows <= this.minValue;
                }
            }
        }
    }
}
