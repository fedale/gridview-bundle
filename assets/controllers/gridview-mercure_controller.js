import { Controller } from '@hotwired/stimulus';

/**
 * Real-time grid updates via Mercure (SSE).
 *
 * Subscribes to the grid's private topic and, on every "something changed"
 * signal, auto-refreshes the grid by re-submitting its filter form (which
 * re-applies the current filters/page/permissions server-side) and shows a
 * short informational toast. The signal carries no record data, so nothing the
 * user isn't allowed to see can leak through the stream.
 */
export default class extends Controller {
    static values = {
        hub:   String,                       // public Mercure hub URL
        topic: String,                       // per-grid topic, e.g. "gridview/customer"
        form:  String,                       // id of the filter form to re-submit
        delay: { type: Number, default: 400 }, // debounce window (coalesces bursts/bulk ops)
    };

    connect() {
        if (!this.hubValue || !this.topicValue) return;

        this._timer = null;

        const url = new URL(this.hubValue);
        url.searchParams.append('topic', this.topicValue);

        // withCredentials → the browser sends the `mercureAuthorization` cookie
        // set at page render, so the private subscription is authorized.
        this._es = new EventSource(url, { withCredentials: true });
        this._es.onmessage = () => this._scheduleRefresh();
    }

    disconnect() {
        clearTimeout(this._timer);
        // Turbo replaces the frame on refresh → close the old stream so we don't
        // pile up orphan connections.
        this._es?.close();
        this._es = null;
    }

    _scheduleRefresh() {
        clearTimeout(this._timer);
        this._timer = setTimeout(() => this._refresh(), this.delayValue);
    }

    _refresh() {
        const form = this.formValue ? document.getElementById(this.formValue) : null;
        if (form && typeof form.requestSubmit === 'function') {
            // Reuses gridview-filter's loading overlay + Turbo frame replacement.
            form.requestSubmit();
        } else {
            // Fallback: reload the enclosing turbo-frame directly.
            this.element.closest('turbo-frame')?.reload();
        }
        this._notify();
    }

    _notify() {
        const gv = this.element.closest('[data-gridview]') ?? this.element;

        let banner = gv.querySelector(':scope > .gv-info-banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.className = 'gv-info-banner';
            gv.prepend(banner);
        }
        banner.textContent = gv.dataset.gvRealtimeMessage
            ?? 'I dati sono stati aggiornati da un altro utente.';
        banner.hidden = false;

        clearTimeout(this._noticeTimer);
        this._noticeTimer = setTimeout(() => { banner.hidden = true; }, 5000);
    }
}
