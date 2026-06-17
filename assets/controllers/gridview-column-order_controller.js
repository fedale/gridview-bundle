import { Controller } from '@hotwired/stimulus';
import { preferenceProvider } from '../preferences.js';

/**
 * Column reorder via native HTML5 drag-and-drop. Draggable headers carry
 * data-col-key; dropping reorders the matching <th> and every row's <td>. The
 * order (a list of column keys) is persisted via the pluggable preference
 * provider (bucket 'columnOrder', scoped per route) and re-applied on connect —
 * so it survives Turbo frame refreshes too. Pure client-side (cosmetic).
 */
export default class extends Controller {
    connect() {
        this._table = this.element.querySelector('table');
        if (!this._table) return;

        this._applySaved();

        this._onDragStart = (e) => {
            const th = e.target.closest('th[draggable="true"]');
            if (!th) return;
            this._dragKey = th.dataset.colKey;
            e.dataTransfer.effectAllowed = 'move';
            th.classList.add('gv-col-dragging');
        };
        this._onDragOver = (e) => {
            if (!this._dragKey) return;
            const th = e.target.closest('th[data-col-key]');
            if (!th) return;
            e.preventDefault();
            this._marker(th);
        };
        this._onDrop = (e) => {
            const th = e.target.closest('th[data-col-key]');
            if (!th || !this._dragKey) return;
            e.preventDefault();
            this._reorder(this._dragKey, th.dataset.colKey);
            this._persist();
            this._cleanup();
        };
        this._onDragEnd = () => this._cleanup();

        const head = this._table.tHead;
        head.addEventListener('dragstart', this._onDragStart);
        head.addEventListener('dragover', this._onDragOver);
        head.addEventListener('drop', this._onDrop);
        head.addEventListener('dragend', this._onDragEnd);
    }

    disconnect() {
        const head = this._table && this._table.tHead;
        if (!head) return;
        head.removeEventListener('dragstart', this._onDragStart);
        head.removeEventListener('dragover', this._onDragOver);
        head.removeEventListener('drop', this._onDrop);
        head.removeEventListener('dragend', this._onDragEnd);
    }

    get _scope() {
        return window.location.pathname;
    }

    // Current header key order (DOM order).
    _order() {
        return [...this._table.tHead.rows[0].cells].map((c) => c.dataset.colKey);
    }

    // Move `from` to `target`'s position and re-apply to the whole table.
    _reorder(from, target) {
        if (from === target) return;
        const order = this._order();
        const fromIdx = order.indexOf(from);
        if (fromIdx >= 0) order.splice(fromIdx, 1);
        order.splice(order.indexOf(target), 0, from);
        this._apply(order);
    }

    _apply(order) {
        this._reorderRow(this._table.tHead.rows[0], order);
        [...this._table.tBodies].forEach((tb) => {
            [...tb.rows].forEach((row) => this._reorderRow(row, order));
        });
    }

    _reorderRow(row, order) {
        order.forEach((key) => {
            const cell = [...row.children].find((c) => c.dataset.colKey === key);
            if (cell) row.appendChild(cell); // appending in order leaves the row sorted
        });
    }

    _applySaved() {
        const saved = preferenceProvider().load(this._scope, 'columnOrder');
        if (!Array.isArray(saved) || saved.length === 0) return;

        const current = this._order();
        // Keep saved keys that still exist, then append any new columns.
        const order = saved.filter((k) => current.includes(k));
        current.forEach((k) => { if (!order.includes(k)) order.push(k); });
        this._apply(order);
    }

    _persist() {
        preferenceProvider().save(this._scope, 'columnOrder', this._order());
    }

    _marker(th) {
        this._clearMarkers();
        th.classList.add('gv-col-drop-target');
    }

    _clearMarkers() {
        this._table.querySelectorAll('.gv-col-drop-target').forEach((el) => el.classList.remove('gv-col-drop-target'));
    }

    _cleanup() {
        this._dragKey = null;
        this._clearMarkers();
        this._table.querySelectorAll('.gv-col-dragging').forEach((el) => el.classList.remove('gv-col-dragging'));
    }
}
