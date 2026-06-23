import { Controller } from '@hotwired/stimulus';

/**
 * Server-side infinite scroll (progressive enhancement over numeric pagination).
 *
 * The grid renders an "infinite section" (`#gv-infinite-<key>`) carrying the current
 * page, the last page and the URL of the next page (with `_rows=1`) on data-*.
 * An IntersectionObserver on the sentinel — or a click on the "Load more" button —
 * fetches the next page as a Turbo Stream that APPENDS rows to the <tbody> and
 * REPLACES the infinite section with fresh state. The controller lives on
 * `[data-gridview]`, so it survives the section replace; it re-reads state from the
 * DOM on every load, following the recreated node.
 *
 * Without JS the section still renders a real "Load more" link/button, so the grid
 * stays usable (and accessible) as a fallback.
 */
export default class extends Controller {
    static targets = ['section', 'sentinel'];

    connect() {
        this._loading = false;
        this._observer = null;
    }

    disconnect() {
        this._observer?.disconnect();
        this._observer = null;
    }

    // Re-arm the observer whenever a sentinel enters the DOM (initial render and
    // after every turbo-stream replace of the section).
    sentinelTargetConnected(el) {
        const rootMargin = this.hasSectionTarget
            ? (this.sectionTarget.dataset.gvRootMargin || '300px')
            : '300px';

        this._observer?.disconnect();
        this._observer = new IntersectionObserver((entries) => {
            if (entries.some((e) => e.isIntersecting)) this.loadMore();
        }, { rootMargin });
        this._observer.observe(el);
    }

    sentinelTargetDisconnected() {
        this._observer?.disconnect();
        this._observer = null;
    }

    async loadMore() {
        if (this._loading) return;

        const section = this.hasSectionTarget
            ? this.sectionTarget
            : this.element.querySelector('.gv-infinite');
        if (!section) return;

        const page = parseInt(section.dataset.gvPage || '1', 10);
        const lastPage = parseInt(section.dataset.gvLastPage || '1', 10);
        const url = section.dataset.gvNextUrl;
        if (!url || page >= lastPage) return;

        this._loading = true;
        section.classList.add('gv-infinite-loading');

        try {
            const res = await fetch(url, {
                headers: { Accept: 'text/vnd.turbo-stream.html' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const html = await res.text();
            // Appends rows + replaces the infinite section (new page/next URL).
            if (window.Turbo) window.Turbo.renderStreamMessage(html);

            // Let other controllers (e.g. responsive collapse) react to new rows.
            this.dispatch('rows-appended', { bubbles: true });
            window.dispatchEvent(new CustomEvent('gridview:rows-appended', {
                detail: { element: this.element },
            }));
        } catch (e) {
            // Keep the button visible so the user can retry; never break the page.
            section.classList.remove('gv-infinite-loading');
        } finally {
            this._loading = false;
        }
    }
}
