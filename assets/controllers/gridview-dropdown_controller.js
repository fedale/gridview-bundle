import { Controller } from '@hotwired/stimulus';

/**
 * Minimal, dependency-free dropdown menu.
 *
 * Toggles `.gv-open` on the `.gv-dropdown-menu` when the trigger is clicked, and
 * closes on Escape, on an outside click, or once a menu item is activated.
 * Replaces the Bootstrap dropdown previously used by {savedSearch}, {export} and
 * the selection-header menu, so the grid needs no Bootstrap JS.
 *
 * Markup:
 *   <div class="gv-dropdown" data-controller="gridview-dropdown">
 *     <button data-action="gridview-dropdown#toggle" aria-expanded="false">…</button>
 *     <ul class="gv-dropdown-menu">
 *       <li><button class="gv-dropdown-item">…</button></li>
 *     </ul>
 *   </div>
 */
export default class extends Controller {
    connect() {
        this._onOutside = (e) => { if (!this.element.contains(e.target)) this._close(); };
        this._onKey = (e) => { if (e.key === 'Escape') this._close(); };
        // Close after an item is chosen (matches Bootstrap's auto-close).
        this._onItem = (e) => { if (e.target.closest('.gv-dropdown-item')) this._close(); };

        document.addEventListener('click', this._onOutside);
        document.addEventListener('keydown', this._onKey);
        this.element.addEventListener('click', this._onItem);
    }

    disconnect() {
        document.removeEventListener('click', this._onOutside);
        document.removeEventListener('keydown', this._onKey);
        this.element.removeEventListener('click', this._onItem);
    }

    toggle(event) {
        event.stopPropagation();
        const open = this._menu()?.classList.toggle('gv-open');
        this._trigger()?.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    _close() {
        this._menu()?.classList.remove('gv-open');
        this._trigger()?.setAttribute('aria-expanded', 'false');
    }

    _menu() { return this.element.querySelector('.gv-dropdown-menu'); }

    _trigger() { return this.element.querySelector('[aria-expanded]'); }
}
