import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        ajaxUrl:     { type: String,  default: '' },
        searchable:  { type: Boolean, default: false },
        optionLabel: { type: String,  default: 'name' },
        optionValue: { type: String,  default: 'id' },
        choices:     { type: Array,   default: [] },
        selected:    { type: Array,   default: [] },
    };

    connect() {
        if (this.ajaxUrlValue) {
            this._loadOptions().then(() => this._enhance());
        } else if (this.choicesValue.length > 0) {
            this._loadChoicesFromData();
            this._enhance();
        } else {
            this._enhance();
        }

        // Sync visual state when the parent form resets (e.g. resetAll)
        this._onReset = () => requestAnimationFrame(() => this._syncFromSelect());
        this.element.form?.addEventListener('reset', this._onReset);
    }

    disconnect() {
        this._closePanel(false);
        this.element.form?.removeEventListener('reset', this._onReset);
        this._teardown();
    }

    _teardown() {
        if (!this._wrapper) return;
        if (this.element.multiple) {
            // select was left outside the wrapper, just show it again
            this.element.style.display = '';
        } else {
            // select was moved inside the wrapper — put it back before removing
            this._wrapper.before(this.element);
        }
        this._wrapper.remove();
        this._wrapper  = null;
        this._trigger  = null;
        this._panel    = null;
        this._panelOpen = false;
    }

    // ── Setup ──────────────────────────────────────────────────────────

    _enhance() {
        if (this.element.multiple) {
            this._buildMultiSelect();
        } else if (this.searchableValue || this.ajaxUrlValue) {
            this._buildSearchableSelect();
        }
    }

    // ── Static choices from data attribute ────────────────────────────

    _loadChoicesFromData() {
        const select   = this.element;
        const selected = new Set(this.selectedValue.map(String));
        select.innerHTML = '';
        this.choicesValue.forEach(item => {
            const opt       = document.createElement('option');
            opt.value       = String(item.v);
            opt.textContent = item.l;
            opt.selected    = selected.has(opt.value);
            select.appendChild(opt);
        });
    }

    // ── AJAX loading ───────────────────────────────────────────────────

    async _loadOptions() {
        const select = this.element;
        const currentValues = [...select.options].filter(o => o.selected).map(o => o.value);

        try {
            const res  = await fetch(this.ajaxUrlValue, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            select.innerHTML = '';
            data.forEach(item => {
                const opt       = document.createElement('option');
                opt.value       = String(item[this.optionValueValue]);
                opt.textContent = item[this.optionLabelValue];
                if (currentValues.includes(opt.value)) opt.selected = true;
                select.appendChild(opt);
            });
        } catch (e) {
            console.error('[gridview-relation-filter] failed to load options:', e);
        }
    }

    // ── Custom multi-select (dropdown + checkboxes) ────────────────────

    _buildMultiSelect() {
        const select = this.element;
        // bfcache guard: remove any wrapper left in the DOM from a previous session
        select.parentNode?.querySelectorAll('.gv-multi-select').forEach(el => el.remove());
        select.style.display = 'none';

        const wrapper = document.createElement('div');
        wrapper.className = 'gv-multi-select';

        // Trigger button
        const trigger = document.createElement('button');
        trigger.type      = 'button';
        trigger.className = 'gv-multi-trigger';
        trigger.addEventListener('click', e => {
            e.stopPropagation();
            this._panelOpen ? this._closePanel() : this._openPanel();
        });

        // Floating panel
        const panel = document.createElement('div');
        panel.className = 'gv-multi-panel';
        panel.style.display = 'none';

        // Search inside panel
        const searchInput = document.createElement('input');
        searchInput.type        = 'text';
        searchInput.placeholder = 'Cerca...';
        searchInput.className   = 'gv-multi-search';
        searchInput.autocomplete = 'off';
        searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
        searchInput.addEventListener('input', () => this._filterItems(searchInput.value));

        const searchWrap = document.createElement('div');
        searchWrap.className = 'gv-multi-search-wrap';
        searchWrap.appendChild(searchInput);
        panel.appendChild(searchWrap);

        // Utility actions: select all / deselect / invert
        const actions = document.createElement('div');
        actions.className = 'gv-multi-actions';
        [
            ['Seleziona visibili', () => this._selectVisible(select, trigger)],
            ['Deseleziona',        () => this._setAll(false, select, trigger)],
            ['Inverti',            () => this._invertAll(select, trigger)],
        ].forEach(([label, handler]) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = label;
            btn.addEventListener('click', e => { e.stopPropagation(); handler(); });
            actions.appendChild(btn);
        });
        panel.appendChild(actions);

        // Checkbox list
        const list = document.createElement('div');
        list.className = 'gv-multi-list';

        [...select.options].filter(o => o.value).forEach(opt => {
            const item = document.createElement('label');
            item.className = 'gv-multi-item';

            const cb    = document.createElement('input');
            cb.type     = 'checkbox';
            cb.value    = opt.value;
            cb.checked  = opt.selected;
            cb.className = 'gv-multi-checkbox';
            cb.addEventListener('change', () => {
                opt.selected = cb.checked;
                this._updateLabel(trigger, select);
            });

            const text = document.createTextNode(opt.textContent.trim());
            item.appendChild(cb);
            item.appendChild(text);
            list.appendChild(item);
        });

        panel.appendChild(list);
        wrapper.appendChild(trigger);
        wrapper.appendChild(panel);

        // Caret indicator
        const caret = document.createElement('span');
        caret.className = 'gv-multi-caret';
        caret.textContent = '▾';
        trigger.appendChild(caret);

        this._trigger  = trigger;
        this._panel    = panel;
        this._wrapper  = wrapper;
        this._panelOpen = false;
        this._outsideClick = e => { if (!wrapper.contains(e.target)) this._closePanel(); };

        select.parentNode.insertBefore(wrapper, select);
        this._updateLabel(trigger, select);
    }

    _openPanel() {
        this._panel.style.display = 'block';
        this._panelOpen = true;
        this._trigger.classList.add('gv-multi-trigger--open');
        document.addEventListener('click', this._outsideClick);
        this._panel.querySelector('.gv-multi-search')?.focus();
    }

    _closePanel(andSubmit = true) {
        if (!this._panel || !this._panelOpen) return;
        this._panel.style.display = 'none';
        this._panelOpen = false;
        this._trigger?.classList.remove('gv-multi-trigger--open');
        document.removeEventListener('click', this._outsideClick);
        if (andSubmit) {
            this.element.form?.requestSubmit();
        }
    }

    _filterItems(query) {
        const needle = query.toLowerCase();
        this._panel.querySelectorAll('.gv-multi-item').forEach(item => {
            item.style.display = needle === '' || item.textContent.toLowerCase().includes(needle)
                ? '' : 'none';
        });
    }

    _selectVisible(select, trigger) {
        // Deselect all first, then select only visible items
        [...select.options].filter(o => o.value).forEach(o => { o.selected = false; });
        this._panel.querySelectorAll('.gv-multi-item').forEach(item => {
            const cb = item.querySelector('input[type="checkbox"]');
            if (!cb) return;
            const visible = item.style.display !== 'none';
            cb.checked = visible;
            const opt = [...select.options].find(o => o.value === cb.value);
            if (opt) opt.selected = visible;
        });
        this._updateLabel(trigger, select);
        this.element.form?.requestSubmit();
    }

    _setAll(checked, select, trigger) {
        [...select.options].filter(o => o.value).forEach(o => { o.selected = checked; });
        this._panel.querySelectorAll('.gv-multi-item input[type="checkbox"]').forEach(cb => {
            cb.checked = checked;
        });
        this._updateLabel(trigger, select);
        this.element.form?.requestSubmit();
    }

    _invertAll(select, trigger) {
        [...select.options].filter(o => o.value).forEach(o => { o.selected = !o.selected; });
        this._panel.querySelectorAll('.gv-multi-item input[type="checkbox"]').forEach(cb => {
            const opt = [...select.options].find(o => o.value === cb.value);
            if (opt) cb.checked = opt.selected;
        });
        this._updateLabel(trigger, select);
        this.element.form?.requestSubmit();
    }

    _updateLabel(trigger, select) {
        const selected = [...select.options].filter(o => o.selected && o.value);
        const label = selected.length > 0 ? `${selected.length} selezionato/i` : 'Tutti';
        // Update text node only, preserving the caret span
        const caret = trigger.querySelector('.gv-multi-caret');
        trigger.textContent = label;
        if (caret) trigger.appendChild(caret);
    }

    // Sync checkboxes + label after form.reset()
    _syncFromSelect() {
        if (!this._panel) return;
        const select = this.element;
        this._panel.querySelectorAll('.gv-multi-item input[type="checkbox"]').forEach(cb => {
            const opt = [...select.options].find(o => o.value === cb.value);
            if (opt) cb.checked = opt.selected;
        });
        this._updateLabel(this._trigger, select);
    }

    // ── Single-select with search ──────────────────────────────────────

    _buildSearchableSelect() {
        // bfcache guard: if already wrapped, unwrap first
        if (this.element.parentElement?.classList.contains('gv-relation-filter-wrapper')) {
            const stale = this.element.parentElement;
            stale.before(this.element);
            stale.remove();
        }
        const wrapper = document.createElement('div');
        wrapper.className = 'gv-relation-filter-wrapper';

        const search = document.createElement('input');
        search.type         = 'text';
        search.placeholder  = 'Cerca...';
        search.className    = 'gv-relation-filter-search';
        search.autocomplete = 'off';
        search.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
        search.addEventListener('input', () => this._filterOptions(search.value));

        const select = this.element;
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(search);
        wrapper.appendChild(select);
        this._wrapper = wrapper;
    }

    _filterOptions(query) {
        const needle = query.toLowerCase();
        [...this.element.options].forEach(opt => {
            if (!opt.value) return;
            opt.hidden = needle !== '' && !opt.textContent.toLowerCase().includes(needle);
        });
    }
}
