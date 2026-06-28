import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import i18n from '../i18n.js';

/**
 * Drives the generated CRUD forms: opens a self-contained modal (gv-modal, no
 * Bootstrap JS), fetches the form partial into it, and submits add/edit/clone/
 * delete via fetch. A text/vnd.turbo-stream.html response refreshes the grid
 * frame and closes the modal; an HTML response (validation errors) is
 * re-injected into the modal.
 *
 * Clean replacement of the app's modal-form_controller.js, shipped by the bundle.
 */
export default class extends Controller {
    static targets = ['modal', 'modalBody'];

    // Trigger: open the modal and load the form (add / edit / clone).
    open(event) {
        event.preventDefault();
        const url = event.params.url
            || event.currentTarget.dataset.url
            || event.currentTarget.getAttribute('href');
        if (!url || url === '#') return;
        this._openUrl(url);
    }

    // Opened from the gridview-selection controller's `open` event (bulk actions).
    openFromEvent(event) {
        const url = event.detail && event.detail.url;
        if (url) this._openUrl(url);
    }

    _openUrl(url) {
        this._spinner();
        this._show();

        fetch(this._withGridQuery(url), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.text())
            .then((html) => { this.modalBodyTarget.innerHTML = html; i18n.apply(this.modalBodyTarget); })
            .catch(() => { this.modalBodyTarget.innerHTML = this._error(); });
    }

    // Intercepts both the modal CRUD form and inline delete forms.
    submit(event) {
        const form = event.target.closest('form');
        if (!form) return;

        const confirmMsg = event.params.confirm;
        if (confirmMsg && !window.confirm(confirmMsg)) {
            event.preventDefault();
            return;
        }
        event.preventDefault();

        // Loading feedback: the save round-trip can take a second or two, during
        // which the modal would otherwise look frozen. Disable the submit button
        // and prefix a spinner. No restore needed: every outcome either hides the
        // modal or replaces the form HTML in modalBody.
        const submitter = event.submitter || form.querySelector('[type="submit"]');
        this._setLoading(submitter);

        fetch(form.action, {
            method: (form.getAttribute('method') || 'post').toUpperCase(),
            body: new FormData(form),
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'text/vnd.turbo-stream.html, text/html',
            },
        })
            .then(async (response) => {
                const contentType = response.headers.get('Content-Type') || '';
                const text = await response.text();

                if (contentType.includes('turbo-stream')) {
                    Turbo.renderStreamMessage(text);
                    this._hide();
                } else {
                    // Validation errors: re-render the form inside the modal.
                    this.modalBodyTarget.innerHTML = text;
                    i18n.apply(this.modalBodyTarget);
                }
            })
            .catch(() => { this.modalBodyTarget.innerHTML = this._error(); });
    }

    // Carry the grid's current filter/sort/page query into the CRUD request.
    // The server rebuilds the grid from the request when emitting the post-save
    // Turbo Stream refresh; without the query it has no filter params and the
    // filter is lost — even though the browser URL still shows it (the stream
    // replaces content without touching the address bar). The form rendered in
    // the modal echoes this URL as its action, so the eventual POST carries it
    // too. Existing params on `url` win over the forwarded grid ones.
    _withGridQuery(url) {
        const grid = window.location.search.replace(/^\?/, '');
        if (!grid) return url;

        const [path, own = ''] = url.split('?');
        const merged = new URLSearchParams(own);
        for (const [k, v] of new URLSearchParams(grid)) {
            if (!merged.has(k)) merged.append(k, v);
        }
        const qs = merged.toString();
        return qs ? `${path}?${qs}` : path;
    }

    // Close action — bound to the header ✕, the Cancel buttons and (via
    // backdropClose) a click on the overlay. Works for buttons inside fetched
    // partials too: they live within this controller's element.
    close(event) {
        if (event) event.preventDefault();
        this._hide();
    }

    // Backdrop click: close only when the click lands on the overlay itself,
    // not on the dialog inside it.
    backdropClose(event) {
        if (event.target === this.modalTarget) this._hide();
    }

    _show() {
        this.modalTarget.classList.add('gv-open');
        this.modalTarget.removeAttribute('aria-hidden');
        this._onKey = (e) => { if (e.key === 'Escape') this._hide(); };
        document.addEventListener('keydown', this._onKey);
    }

    _hide() {
        this.modalTarget.classList.remove('gv-open');
        this.modalTarget.setAttribute('aria-hidden', 'true');
        if (this._onKey) {
            document.removeEventListener('keydown', this._onKey);
            this._onKey = null;
        }
    }

    // Disables the submit button and shows a spinner to its left while the
    // request is in flight.
    _setLoading(button) {
        if (!button) return;
        button.disabled = true;
        button.insertAdjacentHTML(
            'afterbegin',
            '<span class="gv-spinner gv-spinner--inline" role="status" aria-hidden="true"></span>'
        );
    }

    _spinner() {
        this.modalBodyTarget.innerHTML =
            '<div class="gv-modal__loading"><span class="gv-spinner" role="status" aria-hidden="true"></span></div>';
    }

    _error() {
        return `<div class="gv-alert gv-alert--danger">${i18n.t('crud.error')}</div>`;
    }
}
