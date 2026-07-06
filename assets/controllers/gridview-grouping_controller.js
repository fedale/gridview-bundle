import { Controller } from '@hotwired/stimulus';

/**
 * Expand/collapse the child rows of a grouped grid.
 *
 * Each parent row carries a toggle button; the detail row holding its children
 * as a nested sub-table is the immediately following sibling (`.gv-group-detail`),
 * rendered hidden. Clicking the button shows/hides it.
 *
 * Two modes share this controller (the server decides which):
 *   - eager: children are already in the detail row — the toggle just reveals it;
 *   - lazy:  the detail's inner carries `data-gv-group-lazy`; on first expand the
 *     controller fetches the children from `url` and injects them, once.
 *
 * The controller lives on `[data-gridview]`, so it also governs rows appended by
 * infinite scroll (their buttons bind automatically) without extra wiring.
 */
export default class extends Controller {
    static values = { url: String };

    /** Reveal or hide the detail row following the clicked parent row. */
    toggle(event) {
        const btn = event.currentTarget;
        const row = btn.closest('tr');
        const detail = row?.nextElementSibling;
        if (!detail || !detail.classList.contains('gv-group-detail')) return;

        const expanded = btn.getAttribute('aria-expanded') === 'true';
        detail.hidden = expanded;
        btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');

        if (!expanded) {
            const inner = detail.querySelector('[data-gv-group-lazy]');
            if (inner && !inner.dataset.gvGroupLoaded) this._loadChildren(inner);
        }
    }

    /** Fetch a parent's children on first expand (lazy mode). */
    async _loadChildren(inner) {
        const key = inner.dataset.gvGroupKey;
        // No endpoint configured (eager grids, or lazy not yet wired): mark done
        // so the placeholder isn't refetched, and leave the row as rendered.
        if (!this.hasUrlValue || !this.urlValue || !key) {
            inner.dataset.gvGroupLoaded = '1';
            return;
        }

        inner.dataset.gvGroupLoaded = '1';
        inner.classList.add('gv-group-loading');

        const sep = this.urlValue.includes('?') ? '&' : '?';
        const url = `${this.urlValue}${sep}_children=${encodeURIComponent(key)}`;

        try {
            const res = await fetch(url, {
                headers: { Accept: 'text/html' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            inner.innerHTML = await res.text();
        } catch (e) {
            // Allow a retry on the next expand; never break the page.
            delete inner.dataset.gvGroupLoaded;
        } finally {
            inner.classList.remove('gv-group-loading');
        }
    }
}
