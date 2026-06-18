import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import * as Turbo from '@hotwired/turbo';
import i18n from '../i18n.js';

/**
 * Drives the generated CRUD forms: opens a Bootstrap modal, fetches the form
 * partial into it, and submits add/edit/clone/delete via fetch. A
 * text/vnd.turbo-stream.html response refreshes the grid frame and closes the
 * modal; an HTML response (validation errors) is re-injected into the modal.
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
        this._modal().show();

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
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
                    this._modal().hide();
                } else {
                    // Validation errors: re-render the form inside the modal.
                    this.modalBodyTarget.innerHTML = text;
                    i18n.apply(this.modalBodyTarget);
                }
            })
            .catch(() => { this.modalBodyTarget.innerHTML = this._error(); });
    }

    _modal() {
        return Modal.getOrCreateInstance(this.modalTarget);
    }

    // Disables the submit button and shows a spinner to its left while the
    // request is in flight.
    _setLoading(button) {
        if (!button) return;
        button.disabled = true;
        button.insertAdjacentHTML(
            'afterbegin',
            '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>'
        );
    }

    _spinner() {
        this.modalBodyTarget.innerHTML =
            '<div class="text-center py-4 text-muted"><span class="spinner-border" role="status" aria-hidden="true"></span></div>';
    }

    _error() {
        return `<div class="alert alert-danger m-3">${i18n.t('crud.error')}</div>`;
    }
}
