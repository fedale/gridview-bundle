import { Controller } from '@hotwired/stimulus';

/**
 * Keeps the export links in step with what the grid is showing.
 *
 * Column visibility and reordering are client-side only — the server never hears
 * about them — so without this the downloaded file would carry every column in
 * declaration order while the screen shows a subset in another order. On click
 * the link picks up a `cols` parameter: the column keys currently drawn, in the
 * order they are drawn.
 *
 * The keys come from the rendered DOM rather than from the stored preferences.
 * The markup is what the user is actually looking at (whatever wrote it), and
 * every renderer labels its cells the same way — `data-col-key` on the table's
 * header cells, on each card field, on each list field — so one query covers all
 * three. Hidden columns are left out; card and list items repeat their keys, so
 * the first occurrence of each wins.
 */
export default class extends Controller {
    static values = { gridId: String };

    connect() {
        // Capture phase: the href has to be rewritten before the browser follows it.
        this._onClick = (event) => {
            const link = event.target.closest('a[href]');
            if (link && this.element.contains(link)) {
                link.href = this._withColumns(link.href);
            }
        };
        this.element.addEventListener('click', this._onClick, true);
    }

    disconnect() {
        this.element.removeEventListener('click', this._onClick, true);
    }

    _withColumns(href) {
        const url  = new URL(href, window.location.href);
        const keys = this._visibleKeys();

        // No grid on the page (or no keyed cells): say nothing rather than
        // something wrong — the server then exports its full configured set.
        if (keys.length === 0) {
            url.searchParams.delete('cols');
        } else {
            url.searchParams.set('cols', keys.join(','));
        }

        return url.toString();
    }

    _visibleKeys() {
        const grid = document.querySelector(`[data-gv="${CSS.escape(this.gridIdValue)}"]`);
        if (!grid) return [];

        const keys = [];
        grid.querySelectorAll('[data-col-key]').forEach((cell) => {
            const key = cell.dataset.colKey;
            if (!key || keys.includes(key)) return;
            if (getComputedStyle(cell).display === 'none') return;
            keys.push(key);
        });

        return keys;
    }
}
