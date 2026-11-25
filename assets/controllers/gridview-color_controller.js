import { Controller } from '@hotwired/stimulus';

/*
 * A colour that may be none (NullableColorType): picking a colour unchecks "No color", so the
 * colour picked is the one saved. Without this controller the user unchecks it by hand.
 *
 *   <div data-controller="gridview-color">
 *     <input type="color" data-gridview-color-target="color">
 *     <input type="checkbox" data-gridview-color-target="none"> No color
 *   </div>
 */
export default class extends Controller {
    static targets = ['color', 'none'];

    connect() {
        this.onInput = () => {
            if (this.hasNoneTarget) {
                this.noneTarget.checked = false;
            }
        };
        this.colorTarget.addEventListener('input', this.onInput);
    }

    disconnect() {
        this.colorTarget?.removeEventListener('input', this.onInput);
    }
}
