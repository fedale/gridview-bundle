import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu'];
    static values  = { gridId: String };

    get _key() { return `gv-vis-${this.gridIdValue}`; }

    connect() {
        this._restore();
        this._outsideClick = e => {
            if (!this.element.contains(e.target)) {
                this.element.querySelector('.gv-dropdown-menu')?.classList.remove('gv-open');
            }
        };
        document.addEventListener('click', this._outsideClick);

        // When the dropdown lives outside the <turbo-frame> (e.g. in a page
        // sidebar), it does not re-connect on frame swaps — re-apply the stored
        // visibility to the freshly rendered cells.
        this._onFrameRender = e => {
            if (e.target?.id === `gridview-${this.gridIdValue}`) this._restore();
        };
        document.addEventListener('turbo:frame-render', this._onFrameRender);
    }

    disconnect() {
        document.removeEventListener('click', this._outsideClick);
        document.removeEventListener('turbo:frame-render', this._onFrameRender);
    }

    toggleMenu(event) {
        event.stopPropagation();
        const menu = this.element.querySelector('.gv-dropdown-menu');
        if (menu) menu.classList.toggle('gv-open');
    }

    toggle(event) {
        const colIndex = parseInt(event.target.dataset.col);
        const visible  = event.target.checked;
        this._setVisible(colIndex, visible);
        const state = this._load();
        state[colIndex] = visible;
        this._save(state);
    }

    _cells(colIndex) {
        return document.querySelectorAll(
            `table[data-gv="${this.gridIdValue}"] [data-col="${colIndex}"]`
        );
    }

    _setVisible(colIndex, visible) {
        this._cells(colIndex).forEach(cell => {
            cell.style.display = visible ? '' : 'none';
        });
    }

    _load() {
        const stored = sessionStorage.getItem(this._key);
        return stored ? JSON.parse(stored) : {};
    }

    _save(state) {
        sessionStorage.setItem(this._key, JSON.stringify(state));
    }

    _restore() {
        const state = this._load();
        Object.entries(state).forEach(([index, visible]) => {
            const colIndex = parseInt(index);
            this._setVisible(colIndex, visible);
            const cb = this.element.querySelector(`input[data-col="${colIndex}"]`);
            if (cb) cb.checked = visible;
        });
    }
}
