import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { preferenceProvider } from '../preferences.js';
import { promptModal } from '../prompt-modal.js';

/**
 * Saved searches: stores the current querystring (filters + sort) under a name
 * and re-applies it. Persisted via the pluggable preference provider, scoped per
 * route. The bucket is 'searches'; each item is { name, query }.
 */
export default class extends Controller {
    static targets = ['list'];
    static values = { scope: String };

    connect() {
        this._render();
    }

    get _scope() {
        return this.scopeValue || window.location.pathname;
    }

    async save() {
        const items = preferenceProvider().load(this._scope, 'searches');
        const proposed = `ricerca ${new Date().toLocaleDateString('it-IT')} (${items.length + 1})`;
        const name = await promptModal({ title: 'Salva ricerca', label: 'Nome', value: proposed });
        if (!name) return;

        const query = window.location.search;
        const existing = items.findIndex((i) => i.name === name);
        if (existing >= 0) items[existing] = { name, query };
        else items.push({ name, query });

        preferenceProvider().save(this._scope, 'searches', items);
        this._render();
    }

    apply(event) {
        const query = event.params.query || '';
        Turbo.visit(window.location.pathname + query, { action: 'advance' });
    }

    remove(event) {
        event.stopPropagation();
        const items = preferenceProvider().load(this._scope, 'searches');
        items.splice(event.params.index, 1);
        preferenceProvider().save(this._scope, 'searches', items);
        this._render();
    }

    _render() {
        if (!this.hasListTarget) return;
        const items = preferenceProvider().load(this._scope, 'searches');

        if (items.length === 0) {
            this.listTarget.innerHTML = '<li><span class="dropdown-item-text text-muted">Nessuna ricerca salvata</span></li>';
            return;
        }

        this.listTarget.innerHTML = items.map((item, index) => `
            <li class="gv-saved-row">
                <button type="button" class="dropdown-item"
                        data-action="gridview-saved-search#apply"
                        data-gridview-saved-search-query-param="${this._esc(item.query)}">${this._esc(item.name)}</button>
                <button type="button" class="gv-saved-del" title="Elimina"
                        data-action="gridview-saved-search#remove"
                        data-gridview-saved-search-index-param="${index}">✕</button>
            </li>
        `).join('');
    }

    _esc(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML.replace(/"/g, '&quot;');
    }
}
