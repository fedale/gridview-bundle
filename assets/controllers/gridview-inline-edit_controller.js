import { Controller } from '@hotwired/stimulus';

/**
 * Inline cell editing. An editable cell (.gv-editable with data-gv-field/data-gv-id)
 * fetches a single-field editor from `${base}/${id}/${field}` on its trigger, then
 * saves on blur / change / Enter (server-side validation is authoritative) and
 * swaps the cell with the new value. Escape cancels, one cell at a time.
 */
export default class extends Controller {
    static values = { base: String };

    connect() {
        this._editing = null; // { cell, original, saving }
    }

    edit(event) {
        const cell = event.currentTarget;
        if (this._editing && this._editing.cell === cell) return;
        this._cancelActive();

        const id = cell.dataset.gvId;
        const field = cell.dataset.gvField;
        if (!id || !field) return;

        this._editing = { cell, original: cell.innerHTML, saving: false };
        cell.classList.add('gv-editing');
        cell.innerHTML = '<span class="gv-inline-spinner"></span>';

        fetch(`${this.baseValue}/${id}/${field}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.text())
            .then((html) => {
                if (!this._editing || this._editing.cell !== cell) return;
                cell.innerHTML = html;
                this._focus(cell);
            })
            .catch(() => this._cancelActive());
    }

    // Save trigger from OK button / Enter. Guarded against double-fire.
    // The editor has no <form> (it lives inside the grid's filter form, and
    // nested forms are invalid), so we collect the cell's inputs and POST them.
    save() {
        if (!this._editing || this._editing.saving) return;
        const cell = this._editing.cell;
        const { gvId: id, gvField: field } = cell.dataset;
        if (!id || !field) return;

        const data = new FormData();
        cell.querySelectorAll('input, select, textarea').forEach((el) => {
            if (!el.name) return;
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
            data.append(el.name, el.value);
        });

        this._editing.saving = true;

        fetch(`${this.baseValue}/${id}/${field}`, {
            method: 'POST',
            body: data,
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
        })
            .then(async (response) => {
                const text = await response.text();
                if (!this._editing || this._editing.cell !== cell) return;

                if (response.ok) {
                    // New display value + explicit success feedback (✓ + green flash).
                    cell.innerHTML = text + '<span class="gv-saved-badge" title="Salvato">✓</span>';
                    cell.classList.remove('gv-editing');
                    cell.classList.add('gv-saved');
                    setTimeout(() => {
                        cell.classList.remove('gv-saved');
                        cell.querySelector('.gv-saved-badge')?.remove();
                    }, 1800);
                    this._editing = null;
                } else {
                    // Validation error: re-render the editor (form_errors) and retry.
                    cell.innerHTML = text;
                    this._editing.saving = false;
                    this._focus(cell);
                }
            })
            .catch(() => {
                if (this._editing && this._editing.cell === cell) {
                    this._editing.saving = false;
                    this._flagError(cell);
                }
            });
    }

    key(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this._cancelActive();
        } else if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA') {
            event.preventDefault();
            this.save();
        }
    }

    cancel(event) {
        if (event) event.preventDefault();
        this._cancelActive();
    }

    _focus(cell) {
        const input = cell.querySelector('[data-gridview-inline-edit-target="input"]');
        if (input) {
            input.focus();
            if (typeof input.select === 'function') {
                try { input.select(); } catch (_) { /* type without text selection */ }
            }
        }
    }

    _flagError(cell) {
        cell.classList.add('gv-save-error');
        setTimeout(() => cell.classList.remove('gv-save-error'), 2000);
    }

    _cancelActive() {
        if (!this._editing) return;
        const { cell, original } = this._editing;
        this._editing = null;            // clear first so the blur-triggered save is ignored
        cell.innerHTML = original;
        cell.classList.remove('gv-editing');
    }
}
