import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { delay: { type: Number, default: 300 } };

    connect() {
        this._timer = null;

        // After a Turbo frame replaces the form, restore focus to the last active input.
        const frame = this.element.closest('turbo-frame');
        if (frame?.dataset.lastFocusedId) {
            const el = document.getElementById(frame.dataset.lastFocusedId);
            if (el) {
                // Fast typing can land keystrokes in the window between the
                // debounced auto-submit firing and Turbo swapping the frame.
                // _snapshotTyping() stashes the live value + caret on the frame
                // just before the swap; re-apply them here so the server's
                // (older) value doesn't clobber characters the user just typed.
                let resubmit = false;
                if (frame.dataset.gvTypedValue !== undefined
                    && el.value !== frame.dataset.gvTypedValue) {
                    el.value = frame.dataset.gvTypedValue;
                    resubmit = true;
                }

                el.focus();
                // Restore the caret — only for inputs that support text selection
                // (<select>, checkbox, number/date inputs don't and would throw).
                if (typeof el.setSelectionRange === 'function') {
                    try {
                        const caret = frame.dataset.gvCaret !== undefined
                            ? Number(frame.dataset.gvCaret)
                            : el.value.length;
                        el.setSelectionRange(caret, caret);
                    } catch (_) { /* input type doesn't support selection */ }
                }

                // The displayed value is now ahead of what the server filtered
                // on — re-run the filter so the results catch up.
                if (resubmit) this._scheduleSubmit(el);
            }
            delete frame.dataset.gvTypedValue;
            delete frame.dataset.gvCaret;
        }

        // Intercept every form submission: URL-size guard + loading state
        this._onSubmit = e => this._beforeSubmit(e);
        this.element.addEventListener('submit', this._onSubmit);

        // Turbo events: hide loading and surface errors after the request settles
        this._onSubmitEnd = e => {
            if (e.target !== this.element) return;
            this._hideLoading();
            // Show error for server failures (5xx); ignore 4xx (e.g. validation)
            const status = e.detail?.fetchResponse?.statusCode;
            if (e.detail?.success === false && (!status || status >= 500)) {
                this._showError();
            }
        };
        // Network-level errors (connection closed, timeout, etc.)
        this._onFetchError = e => {
            const frame = this.element.closest('turbo-frame');
            if (e.target !== this.element && e.target !== frame) return;
            this._hideLoading();
            this._showError();
        };
        document.addEventListener('turbo:submit-end', this._onSubmitEnd);
        document.addEventListener('turbo:fetch-request-error', this._onFetchError);

        // Just before Turbo swaps the frame, snapshot the value + caret of the
        // field being typed in. Without this, characters typed while the request
        // is in flight (fast typing) are lost when the frame is replaced. The
        // focus-restore block in connect() re-applies the snapshot afterwards.
        this._onBeforeFrameRender = e => this._snapshotTyping(e);
        document.addEventListener('turbo:before-frame-render', this._onBeforeFrameRender);

        // Filter widgets associated to this form via HTML `form="<id>"` but rendered
        // OUTSIDE the controller's DOM (e.g. a filterBar placed in a page sidebar)
        // can't reach the Stimulus `data-action`. Delegate from the document so the
        // debounced auto-submit still fires for them.
        this._onDetachedInput = e => {
            const el = e.target;
            if (el.form === this.element
                && !this.element.contains(el)
                && this._isFilterField(el)) {
                this._mirrorToHeader(el);
                this._scheduleSubmit(el);
            }
        };
        document.addEventListener('input',  this._onDetachedInput);
        document.addEventListener('change', this._onDetachedInput);

        this._applyHighlights();
        this._updateHeaderIcons();
    }

    disconnect() {
        clearTimeout(this._timer);
        this.element.removeEventListener('submit', this._onSubmit);
        document.removeEventListener('turbo:submit-end', this._onSubmitEnd);
        document.removeEventListener('turbo:fetch-request-error', this._onFetchError);
        document.removeEventListener('turbo:before-frame-render', this._onBeforeFrameRender);
        document.removeEventListener('input',  this._onDetachedInput);
        document.removeEventListener('change', this._onDetachedInput);
    }

    // ── Actions ────────────────────────────────────────────────────────

    input(event) {
        this._scheduleSubmit(event.target);
    }

    filterBarInput(event) {
        this._mirrorToHeader(event.target);
        this._scheduleSubmit(event.target);
    }

    mirrorInput(event) {
        const attr = event.target.dataset.gvMirrorAttr;
        if (!attr) return;

        const realInput = this.element.querySelector(`[data-gv-fb-attr="${attr}"]`);
        if (realInput) realInput.value = event.target.value;

        this._scheduleSubmit(event.target);
    }

    // Debounced auto-submit shared by inline, filterBar (in-grid or detached) and
    // mirror inputs.
    _scheduleSubmit(target) {
        // If there is no enclosing <turbo-frame> (useTurbo: false), skip auto-submit.
        const frame = this.element.closest('turbo-frame');
        if (!frame) return;

        if (target?.id) frame.dataset.lastFocusedId = target.id;

        clearTimeout(this._timer);
        this._timer = setTimeout(() => {
            this._updateHeaderIcons();
            this.element.requestSubmit();
        }, this.delayValue);
    }

    // Copy a filterBar widget's value into its mirror input(s) in the header (if any).
    _mirrorToHeader(target) {
        const attr = target.dataset.gvFbAttr;
        if (!attr) return;
        this.element.querySelectorAll(`[data-gv-mirror-attr="${attr}"]`)
            .forEach(el => { el.value = target.value; });
    }

    toggleFilter(event) {
        const field  = event.params.field;
        const inputs = this._findFilterInputs(field);
        const active = inputs.some(el => this._hasValue(el));

        if (active) {
            inputs.forEach(el => this._clearInput(el));
            this._updateHeaderIcons();
            this.element.requestSubmit();
        } else {
            const first = inputs[0];
            if (first) {
                first.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                first.focus();
            }
        }
    }

    clearFilter(event) {
        const field = event.params.field;
        this._findFilterInputs(field).forEach(el => this._clearInput(el));
        this._updateHeaderIcons();
        this.element.requestSubmit();
    }

    resetAll() {
        this.element.reset();
        this.element.querySelectorAll('[data-gv-mirror-attr]').forEach(el => { el.value = ''; });
        this._updateHeaderIcons();
        this.element.requestSubmit();
    }

    // Capture the live value + caret of the focused filter input right before
    // Turbo replaces the frame, so fast-typed characters aren't dropped.
    _snapshotTyping(event) {
        const frame = this.element.closest('turbo-frame');
        if (!frame || event.target !== frame) return;

        const el = document.activeElement;
        if (!el || !el.id || el.form !== this.element
            || !this._isFilterField(el) || !this._isTextEntry(el)) return;

        frame.dataset.gvTypedValue = el.value;
        if (typeof el.selectionStart === 'number') {
            frame.dataset.gvCaret = String(el.selectionStart);
        }
    }

    _isTextEntry(el) {
        if (el.tagName === 'TEXTAREA') return true;
        if (el.tagName !== 'INPUT') return false;
        return ['text', 'search', 'number', 'email', 'url', 'tel', 'password', '']
            .includes(el.type);
    }

    // ── Submit guard ───────────────────────────────────────────────────

    _beforeSubmit(event) {
        // Block GET submissions whose query string would exceed the server's URL limit.
        // Configurable via data-gv-max-query-length on the [data-gridview] container.
        if (this.element.method.toLowerCase() === 'get') {
            const fd    = new FormData(this.element);
            const qs    = new URLSearchParams(fd).toString();
            const limit = Number(this._gv()?.dataset.gvMaxQueryLength ?? 4000);
            if (qs.length > limit) {
                event.preventDefault();
                // Identify which array fields are contributing most (e.g. multi-select)
                const fieldCounts = {};
                for (const key of new URLSearchParams(fd).keys()) {
                    const m = key.match(/\[([^\]]+)\]\[\]$/);
                    if (m) fieldCounts[m[1]] = (fieldCounts[m[1]] || 0) + 1;
                }
                const heavy = Object.entries(fieldCounts)
                    .filter(([, n]) => n > 5)
                    .sort(([, a], [, b]) => b - a)
                    .map(([name, n]) => `"${name}" (${n} valori)`);
                let msg = `La query URL supera il limite consentito (${qs.length} / ${limit} byte).`;
                msg += heavy.length > 0
                    ? ` Riduci la selezione in: ${heavy.join(', ')}.`
                    : ' Riduci il numero di valori selezionati nei filtri e riprova.';
                this._showError(msg);
                return;
            }
        }
        this._clearError();
        this._showLoading();
    }

    // ── Loading & error feedback ───────────────────────────────────────

    _gv() { return this.element.closest('[data-gridview]'); }

    _showLoading() {
        // In Turbo mode the CSS `turbo-frame[busy]` overlay handles the loading
        // state (and survives the frame swap). Stacking a JS overlay on top just
        // double-dims and flickers, so only use it as the non-Turbo fallback.
        if (this.element.closest('turbo-frame')) return;

        const gv = this._gv();
        if (!gv || gv.querySelector('.gv-loading-overlay')) return;
        const overlay = document.createElement('div');
        overlay.className = 'gv-loading-overlay';
        overlay.setAttribute('aria-hidden', 'true');
        const spinner = document.createElement('div');
        spinner.className = 'gv-spinner';
        overlay.appendChild(spinner);
        gv.appendChild(overlay);
    }

    _hideLoading() {
        this._gv()?.querySelector('.gv-loading-overlay')?.remove();
    }

    _showError(message) {
        const gv = this._gv();
        if (!gv) return;
        const msg = message
            ?? gv.dataset.gvErrorMessage
            ?? 'Si è verificato un errore di comunicazione con il server. Riprova.';

        let banner = gv.querySelector(':scope > .gv-error-banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.className = 'gv-error-banner';
            // Close button
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'gv-error-banner__close';
            close.textContent = '✕';
            close.addEventListener('click', () => this._clearError());
            banner.appendChild(close);
            gv.prepend(banner);
        }
        // Update message text node (keep close button)
        const close = banner.querySelector('.gv-error-banner__close');
        banner.childNodes.forEach(n => { if (n !== close) n.remove(); });
        banner.prepend(document.createTextNode(msg));
        banner.hidden = false;
        this._hideLoading();
    }

    _clearError() {
        const banner = this._gv()?.querySelector(':scope > .gv-error-banner');
        if (banner) banner.hidden = true;
    }

    // ── Header icon state ──────────────────────────────────────────────

    _updateHeaderIcons() {
        this.element.querySelectorAll('[data-gridview-filter-field-param]').forEach(btn => {
            const field  = btn.dataset.gridviewFilterFieldParam;
            const inputs = this._findFilterInputs(field);
            const active = inputs.some(el => this._hasValue(el));
            btn.classList.toggle('gv-filter-icon--active', active);
        });
    }

    // ── Helpers ────────────────────────────────────────────────────────

    _findFilterInputs(field) {
        // Use form.elements (not querySelectorAll) so inputs associated via
        // `form="<id>"` but rendered outside the form's DOM are also found.
        return [...this.element.elements].filter(el =>
            el.name && (el.name.includes(`[${field}]`) || el.name === field)
        );
    }

    _isFilterField(el) {
        if (!el.name) return false;
        const tag = el.tagName;
        if (tag === 'SELECT' || tag === 'TEXTAREA') return true;
        if (tag === 'INPUT') {
            return !['submit', 'reset', 'button', 'hidden'].includes(el.type);
        }
        return false;
    }

    _hasValue(el) {
        if (el.tagName === 'SELECT') {
            // A hydrated <select> is the source of truth.
            if (el.options.length > 0) {
                return [...el.options].some(o => o.selected && o.value !== '');
            }
            // Relation filters strip their <option>s server-side (they're moved
            // into data attributes and rebuilt lazily by the relation-filter
            // controller). Until that happens the <select> is empty, so fall
            // back to the server-rendered selection.
            const raw = el.dataset.gridviewRelationFilterSelectedValue;
            if (raw) {
                try { return JSON.parse(raw).length > 0; } catch (_) { /* ignore */ }
            }
            return false;
        }
        return el.value !== '' && el.value !== null;
    }

    _clearInput(el) {
        if (el.tagName === 'SELECT') {
            [...el.options].forEach(o => { o.selected = false; });
        } else {
            el.value = '';
        }
    }

    // ── Highlight ──────────────────────────────────────────────────────

    _applyHighlights() {
        const term = this._searchTerm();
        if (!term) return;
        const tbody = this.element.querySelector('tbody');
        if (!tbody) return;
        this._walk(tbody, term);
    }

    _searchTerm() {
        const input = this.element.querySelector('[name$="[_q]"]');
        return input?.value?.trim() ?? '';
    }

    _walk(node, term) {
        if (node.nodeType === Node.TEXT_NODE) {
            this._highlightTextNode(node, term);
        } else if (
            node.nodeType === Node.ELEMENT_NODE &&
            node.tagName !== 'MARK' &&
            node.tagName !== 'SCRIPT' &&
            node.tagName !== 'STYLE'
        ) {
            [...node.childNodes].forEach(child => this._walk(child, term));
        }
    }

    _highlightTextNode(node, term) {
        const text   = node.textContent;
        const lower  = text.toLowerCase();
        const needle = term.toLowerCase();

        if (!lower.includes(needle)) return;

        const parts = [];
        let lastIdx = 0;
        let idx = lower.indexOf(needle, 0);

        while (idx !== -1) {
            if (idx > lastIdx) parts.push(document.createTextNode(text.slice(lastIdx, idx)));
            const mark = document.createElement('mark');
            mark.className = 'gv-highlight';
            mark.textContent = text.slice(idx, idx + term.length);
            parts.push(mark);
            lastIdx = idx + term.length;
            idx = lower.indexOf(needle, lastIdx);
        }

        if (lastIdx < text.length) parts.push(document.createTextNode(text.slice(lastIdx)));
        node.replaceWith(...parts);
    }
}
