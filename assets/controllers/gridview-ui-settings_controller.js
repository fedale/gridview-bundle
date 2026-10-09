import { Controller } from '@hotwired/stimulus';

/*
 * Drives the UI settings modal (templates/ui_settings/_modal.html.twig).
 *
 * - open: shows the modal and (re)loads its turbo-frame on the current grid's
 *   scope (`grid` value), or the global scope on pages without a grid.
 * - switchScope: the scope <select> submits its GET form, which Turbo turns
 *   into a navigation of the frame — no custom fetch.
 * - savedTargetConnected: a successful save redirects to the frame with a
 *   `saved` marker; when it lands, every grid frame on the page is reloaded so
 *   the new settings apply without a full page reload.
 *
 * Open state is the `gv-open` class, as in the gridview-crud modal.
 */
export default class extends Controller {
    static targets = ['modal', 'frame', 'saved'];
    static values = { url: String, grid: String };

    disconnect() {
        this._unbindKey();
    }

    open(event) {
        if (event) event.preventDefault();

        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('scope', this.gridValue || '_global');
        this._navigate(this.frameTarget, url.toString());

        this.modalTarget.classList.add('gv-open');
        this.modalTarget.removeAttribute('aria-hidden');
        this._onKey = (e) => { if (e.key === 'Escape') this.close(); };
        document.addEventListener('keydown', this._onKey);
    }

    close(event) {
        if (event) event.preventDefault();
        this.modalTarget.classList.remove('gv-open');
        this.modalTarget.setAttribute('aria-hidden', 'true');
        this._unbindKey();
    }

    // Close only when the click lands on the overlay itself, not the dialog.
    backdropClose(event) {
        if (event.target === this.modalTarget) this.close();
    }

    switchScope(event) {
        event.target.form.requestSubmit();
    }

    savedTargetConnected() {
        // Re-fetch each grid region from the current URL: the server applies the
        // new settings, Turbo swaps only the matching frame. A `?view=` in the
        // URL is kept on purpose — the switcher's explicit choice still wins.
        document.querySelectorAll('turbo-frame[id^="gridview-"]').forEach((frame) => {
            this._navigate(frame, window.location.href);
        });
    }

    // Setting the same src again is a no-op for Turbo, so reload in that case.
    _navigate(frame, url) {
        if (frame.src === url) {
            frame.reload();
        } else {
            frame.src = url;
        }
    }

    _unbindKey() {
        if (this._onKey) {
            document.removeEventListener('keydown', this._onKey);
            this._onKey = null;
        }
    }
}
