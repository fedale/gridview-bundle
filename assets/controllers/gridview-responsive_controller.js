import { Controller } from '@hotwired/stimulus';

/**
 * Priority-based responsive collapse.
 *
 * When the table's natural width exceeds its container, the least important
 * columns (highest `data-priority`) are hidden and folded into an expandable
 * detail row, DataTables-style — but entirely client-side: every cell is
 * already in the DOM (tbody renders all columns), so there is no server
 * roundtrip. Columns with priority 0 are pinned and never collapse.
 *
 * The overflow signal comes from a `.gv-resp-wrap` element whose table is sized
 * to its content (width:auto; min-width:100%), so `table.offsetWidth` reflects
 * the natural width and overflows the wrapper when it doesn't fit.
 *
 * Cells are collapsed with the `gv-resp-collapsed` class, NOT inline display,
 * so this layer never fights the column-visibility controller (which toggles
 * inline `style.display`). User-hidden columns are skipped entirely.
 */
export default class extends Controller {
    static targets = ['wrap'];

    connect() {
        this._frame = null;
        this._ro = new ResizeObserver(() => this._schedule());
        if (this.hasWrapTarget) this._ro.observe(this.element);
        // Defer the first pass one frame so sibling controllers (visibility
        // restore, etc.) have applied their inline state before we measure.
        this._schedule();
    }

    disconnect() {
        this._ro?.disconnect();
        if (this._frame) cancelAnimationFrame(this._frame);
    }

    _schedule() {
        if (this._frame) cancelAnimationFrame(this._frame);
        this._frame = requestAnimationFrame(() => {
            this._frame = null;
            this._apply();
        });
    }

    _wrap()  { return this.hasWrapTarget ? this.wrapTarget : this.element.querySelector('.gv-resp-wrap'); }
    _table() { return this._wrap()?.querySelector('table[data-gv]'); }

    /** A column the user explicitly hid keeps an inline display:none on its <th>. */
    _userHidden(th) { return th.style.display === 'none'; }

    _apply() {
        const wrap = this._wrap();
        const table = this._table();
        if (!wrap || !table) return;

        // 1. Reset to the fully-expanded state before measuring.
        this._closeAllDetails();
        table.querySelectorAll('.gv-resp-collapsed').forEach(el => el.classList.remove('gv-resp-collapsed'));
        wrap.classList.remove('gv-has-collapsed');

        // 2. Build the collapse queue: collapsible (priority>0), not user-hidden,
        //    ordered highest-priority-first; ties drop the rightmost column first.
        const queue = [...table.querySelectorAll('thead th[data-col]')]
            .map(th => ({ th, col: th.dataset.col, priority: parseInt(th.dataset.priority || '0', 10) }))
            .filter(h => h.priority > 0 && !this._userHidden(h.th))
            .sort((a, b) => (b.priority - a.priority) || (parseInt(b.col, 10) - parseInt(a.col, 10)));

        // 3. Collapse one column at a time until the table fits (or queue empties).
        let collapsed = 0;
        for (const item of queue) {
            if (!this._overflows(wrap, table)) break;
            this._setCollapsed(table, item.col, true);
            collapsed++;
        }

        if (collapsed > 0) wrap.classList.add('gv-has-collapsed');
    }

    _overflows(wrap, table) {
        // min-width:100% keeps offsetWidth >= clientWidth; a >1px excess is real overflow.
        return table.offsetWidth - wrap.clientWidth > 1;
    }

    _cells(table, col) {
        return table.querySelectorAll(`[data-col="${col}"]`);
    }

    _setCollapsed(table, col, on) {
        this._cells(table, col).forEach(cell => cell.classList.toggle('gv-resp-collapsed', on));
    }

    /** Expand / collapse the detail row for the clicked row's hidden cells. */
    toggle(event) {
        const btn = event.currentTarget;
        const row = btn.closest('tr');
        const table = this._table();
        if (!row || !table) return;

        const next = row.nextElementSibling;
        if (next && next.classList.contains('gv-resp-detail')) {
            next.remove();
            btn.setAttribute('aria-expanded', 'false');
            return;
        }

        const items = this._collapsedFor(table, row);
        if (items.length === 0) return;

        const colspan = [...row.children].filter(c => c.offsetParent !== null).length || row.children.length;
        const tr = document.createElement('tr');
        tr.className = 'gv-resp-detail';
        const td = document.createElement('td');
        td.colSpan = colspan;
        td.innerHTML = items.map(it =>
            `<div class="gv-resp-detail-item"><span class="gv-resp-detail-label">${it.label}</span>` +
            `<span class="gv-resp-detail-value">${it.value}</span></div>`
        ).join('');
        tr.appendChild(td);
        row.after(tr);
        btn.setAttribute('aria-expanded', 'true');
    }

    /** Label/value pairs for the columns collapsed in this row (skips user-hidden). */
    _collapsedFor(table, row) {
        const out = [];
        table.querySelectorAll('thead th.gv-resp-collapsed[data-col]').forEach(th => {
            if (this._userHidden(th)) return;
            const col = th.dataset.col;
            const cell = row.querySelector(`[data-col="${col}"]`);
            const label = th.querySelector('.gv-th__label')?.textContent.trim() || th.textContent.trim();
            out.push({ label, value: cell ? cell.innerHTML : '' });
        });
        return out;
    }

    _closeAllDetails() {
        const table = this._table();
        table?.querySelectorAll('tr.gv-resp-detail').forEach(tr => tr.remove());
        table?.querySelectorAll('.gv-resp-toggle[aria-expanded="true"]')
            .forEach(b => b.setAttribute('aria-expanded', 'false'));
    }
}
