import { Controller } from '@hotwired/stimulus';

/*
 * A tags control: free text labels drawn as chips.
 *
 *   <input type="text" name="…" value="urgent, client-x" data-controller="gridview-tags"
 *          data-gridview-tags-suggestions-value='["urgent","later"]'
 *          data-gridview-tags-restrict-value="true"      (only the suggestions)
 *          data-gridview-tags-max-value="5"              (the most labels)
 *          data-gridview-tags-max-length-value="50"      (the longest label)
 *          data-gridview-tags-url-value="/tags/suggest"  (?query=… in, a JSON list of strings out)
 *          data-gridview-tags-remove-label-value="Remove %tag%"
 *          data-gridview-tags-placeholder-value="Add a tag">
 *
 * The input stays the field the form submits, hidden, its labels separated by commas: without
 * this controller it is a plain text field. In its place: a chip per label with a remove
 * button, then an input adding labels (Enter or a comma adds one, Backspace on an empty input
 * removes the last one) with the suggestions as its datalist. Labels are compared
 * case-insensitively; one matching a suggestion takes its spelling. The server normalizes and
 * checks them again (TagsType). Everything listened to is created here and removed in
 * disconnect(), so a Turbo render starts again from the input. Styled by .gv-tags (_forms.scss).
 */
export default class extends Controller {
    static values = {
        suggestions: Array,
        restrict: Boolean,
        max: Number,
        maxLength: Number,
        url: String,
        removeLabel: { type: String, default: 'Remove %tag%' },
        placeholder: String,
    };

    connect() {
        this.input = this.element;
        this.tags = this.parse(this.input.value);
        this.remote = [];

        this.box = document.createElement('div');
        this.box.className = 'gv-tags';
        this.list = document.createElement('ul');
        this.list.className = 'gv-tags__list';
        this.entry = document.createElement('input');
        this.entry.type = 'text';
        this.entry.className = 'gv-tags__entry';
        this.entry.autocomplete = 'off';
        this.entry.placeholder = this.placeholderValue;
        this.datalist = document.createElement('datalist');
        this.datalist.id = `${this.input.id}_suggestions`;
        this.entry.setAttribute('list', this.datalist.id);
        if (this.input.disabled) {
            this.entry.disabled = true;
        }
        this.box.append(this.list, this.entry, this.datalist);
        this.input.after(this.box);
        this.input.hidden = true;

        // The field's label names the input the user types in.
        this.label = this.input.id ? document.querySelector(`label[for="${CSS.escape(this.input.id)}"]`) : null;
        if (this.label) {
            this.entry.id = `${this.input.id}_entry`;
            this.label.htmlFor = this.entry.id;
        }

        this.box.addEventListener('click', this.onClick = (event) => this.click(event));
        this.entry.addEventListener('keydown', this.onKeydown = (event) => this.keydown(event));
        this.entry.addEventListener('input', this.onInput = (event) => this.typed(event));
        this.entry.addEventListener('blur', this.onBlur = () => this.commit());

        this.render();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.abort?.abort();
        this.box?.remove();
        if (this.input) {
            this.input.hidden = false;
        }
        if (this.label) {
            this.label.htmlFor = this.input.id;
        }
    }

    keydown(event) {
        if ('Enter' === event.key || ',' === event.key) {
            event.preventDefault();
            this.commit();
        } else if ('Backspace' === event.key && '' === this.entry.value && this.tags.length > 0) {
            this.tags.pop();
            this.update();
        }
    }

    typed(event) {
        this.entry.removeAttribute('aria-invalid');
        // A suggestion picked from the list, or labels pasted with their commas.
        if (!event.inputType || 'insertReplacementText' === event.inputType || this.entry.value.includes(',')) {
            if (!event.inputType && !this.isSuggestion(this.entry.value)) {
                return;
            }
            this.commit();

            return;
        }
        this.suggest(this.entry.value);
    }

    click(event) {
        const button = event.target.closest('[data-index]');
        if (!button || this.input.disabled) {
            return;
        }
        this.tags.splice(Number(button.dataset.index), 1);
        this.update();
        this.entry.focus();
    }

    // Adds the labels typed in the entry; those refused (too long, not a suggestion with
    // restrict, past the most labels) stay there, marked invalid.
    commit() {
        const refused = [];
        for (const label of this.parse(this.entry.value)) {
            if (!this.add(label)) {
                refused.push(label);
            }
        }
        this.entry.value = refused.join(', ');
        if (refused.length > 0) {
            this.entry.setAttribute('aria-invalid', 'true');
        } else {
            this.entry.removeAttribute('aria-invalid');
        }
        this.update();
    }

    add(label) {
        if (this.maxLengthValue > 0 && [...label].length > this.maxLengthValue) {
            return false;
        }
        const suggestion = this.suggestionsValue.find((known) => known.toLowerCase() === label.toLowerCase());
        if (this.restrictValue && undefined === suggestion) {
            return false;
        }
        if (this.tags.some((tag) => tag.toLowerCase() === label.toLowerCase())) {
            return true;
        }
        if (this.maxValue > 0 && this.tags.length >= this.maxValue) {
            return false;
        }
        this.tags.push(suggestion ?? label);

        return true;
    }

    update() {
        this.input.value = this.tags.join(', ');
        this.input.dispatchEvent(new Event('change', { bubbles: true }));
        this.render();
    }

    render() {
        this.list.replaceChildren(...this.tags.map((tag, index) => {
            const chip = document.createElement('li');
            chip.className = 'gv-tags__chip';
            const text = document.createElement('span');
            text.textContent = tag;
            chip.append(text);
            if (!this.input.disabled) {
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'gv-tags__remove';
                remove.dataset.index = String(index);
                remove.setAttribute('aria-label', this.removeLabelValue.replace('%tag%', tag));
                remove.textContent = '×';
                chip.append(remove);
            }

            return chip;
        }));
        const full = this.maxValue > 0 && this.tags.length >= this.maxValue;
        this.entry.hidden = full;
        this.renderSuggestions();
    }

    renderSuggestions() {
        const taken = new Set(this.tags.map((tag) => tag.toLowerCase()));
        const offered = [...this.suggestionsValue, ...(this.restrictValue ? [] : this.remote)]
            .filter((label, index, all) => !taken.has(label.toLowerCase())
                && all.findIndex((other) => other.toLowerCase() === label.toLowerCase()) === index);
        this.datalist.replaceChildren(...offered.map((label) => {
            const option = document.createElement('option');
            option.value = label;

            return option;
        }));
    }

    // Asks the URL for more labels matching the text typed, once the user pauses.
    suggest(text) {
        if (!this.urlValue || '' === text.trim()) {
            return;
        }
        clearTimeout(this.timer);
        this.timer = setTimeout(async () => {
            this.abort?.abort();
            this.abort = new AbortController();
            const url = new URL(this.urlValue, window.location.origin);
            url.searchParams.set('query', text.trim());
            try {
                const response = await fetch(url, { signal: this.abort.signal, headers: { Accept: 'application/json' } });
                const labels = response.ok ? await response.json() : [];
                this.remote = Array.isArray(labels) ? labels.filter((label) => 'string' === typeof label) : [];
                this.renderSuggestions();
            } catch (error) {
                if ('AbortError' !== error.name) {
                    console.error(error);
                }
            }
        }, 250);
    }

    isSuggestion(text) {
        const label = text.trim().toLowerCase();

        return [...this.suggestionsValue, ...this.remote].some((known) => known.toLowerCase() === label);
    }

    parse(text) {
        return text.split(',').map((label) => label.replace(/\s+/gu, ' ').trim()).filter((label) => '' !== label);
    }
}
