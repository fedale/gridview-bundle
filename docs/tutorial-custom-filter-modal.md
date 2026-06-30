# Tutorial — A custom, EasyAdmin-style filter modal

> **Level:** intermediate · **Time:** ~20 min · **Bundle changes:** none
> ← Back to the [main documentation](index.md) · related: [Filtering & Search](index.md#filtering--search)

This tutorial builds a **fully custom filter UI** for a Fedale Gridview grid: a **"Filter"
button + modal** in the [EasyAdmin](https://symfony.com/bundles/EasyAdminBundle) style —
a comparison `<select>` per field (*contains*, *starts with*, *exactly*, …), an active
filter count, a "reset all" ✕, and an *Apply* button.

The point is **not** that the bundle ships this widget — it doesn't, and it doesn't need
to. The point is to show how far you can push Fedale Gridview **from the outside**: the
built-in [filterBar and header filters](index.md#filtering--search) are just *one* UI over
a query layer that is completely decoupled from presentation. Once you understand the
contract, you can render filters as a sidebar, a popover, a modal — anything — and the
grid applies them unchanged. **Everything here lives in your application; the bundle is
untouched.**

The complete, runnable result is in the **`gridview-demo`** project:
`src/Controller/Gridview/TagController.php`, `templates/gridview/tag/index.html.twig`
and `assets/controllers/tag_filter_controller.js`.

---

## The contract you build on

Three facts make a custom filter UI a *client-only* job:

1. **Filters travel as GET params under `fedaleForm`.** A column's filter for attribute `name`
   submits as `fedaleForm[name]`. The grid binds this to its `SearchForm` and applies it via
   [`applyFilters()`](index.md#applying-filters-in-the-repository--applyfilters) and the
   type appliers. **Any** input named `fedaleForm[<attribute>]`, submitted (GET) to the grid
   route, is applied — no matter where it sits in the DOM. So a custom modal only has to
   emit the right field names.

2. **The `text` applier understands an explicit operator prefix.** The first token of the
   value selects the operator (`like foo`, `startwith foo`, `eq foo`, …) and disables the
   client wildcard — see the [`text` filter reference](index.md#text). This is what lets a
   `<select>` drive the comparison.

3. **The bundle's styles are scoped under `[data-gridview]`.** Put a bare `data-gridview`
   attribute on your wrapper and the `--gv-*` design tokens and `.gv-modal` / `.gv-btn`
   rules apply to your markup for free — even outside the grid element.

---

## Step 1 — Hide the per-column filter, keep the field

We don't want the inline header input; we want the field rendered in our modal instead.
Set `filterBar: true` on the column (it leaves the `<thead>` but stays in the `SearchForm`)
and make sure `{filterBar}` is not in the layout — see
[The filterBar](index.md#the-filterbar--placing-filters-anywhere).

```php
// src/Controller/Gridview/TagController.php — buildColumns()
[
    'attribute' => 'name',
    'filter'    => ['type' => 'text'],
    'filterBar' => true,   // out of the <thead>, still in the SearchForm
],
```

```php
// viewConfig() — drop the header region entirely for this grid
'options' => [
    'layout' => ['shell' => '{dataview} {footer}'],
],
```

---

## Step 2 — The button, the reset, and the modal

Render them in your host template. They can live anywhere — here, in the page
content-header, *outside* the grid element. The `data-gridview` attribute scopes the
bundle styling onto this island; the `data-controller="tag-filter"` wires our Stimulus
controller (Step 4).

The modal wraps a plain **GET `<form>`** pointing at the grid route — so *Apply* is just a
native form submit, and **reset** is a plain link to the bare index (dropping `fedaleForm`
makes the data provider reapply its defaults, exactly like EasyAdmin's reset).

```twig
{# templates/gridview/tag/index.html.twig #}
{% set _active = form['name'].vars.value is not empty %}

<div class="content-header-actions" data-controller="tag-filter" data-gridview>
    <div class="btn-group">
        <button type="button" class="{{ gridview.cls('btn') }}" data-action="tag-filter#open">
            {{ 'tag.filter.button'|trans }}{% if _active %} <span>(1)</span>{% endif %}
        </button>

        {% if _active %}
            <a href="{{ path('gridview_tag_index') }}" class="{{ gridview.cls('btn') }}"
               title="{{ 'tag.filter.reset'|trans }}">✕</a>
        {% endif %}
    </div>

    {# Self-contained modal: gv-modal classes, no Bootstrap JS. #}
    <div class="gv-modal" data-tag-filter-target="modal"
         data-action="click->tag-filter#backdropClose">
        <div class="gv-modal__dialog">
            <div class="gv-modal__header">
                <h2 class="gv-modal__title">{{ 'tag.filter.title'|trans }}</h2>
                <button type="button" class="gv-modal__close" data-action="tag-filter#close">&times;</button>
            </div>

            <form method="get" action="{{ path('gridview_tag_index') }}"
                  data-action="submit->tag-filter#apply">
                <div class="gv-modal__body">
                    <div data-tag-filter-row>
                        <label>
                            <input type="checkbox" data-tag-filter-toggle
                                   data-action="change->tag-filter#toggle"
                                   {{ _active ? 'checked' : '' }}>
                            {{ 'tag.name'|trans }}
                        </label>

                        {# Comparison operator — UI only, NO `name`: it never leaks into the query. #}
                        <select class="form-select" data-tag-filter-comparison>
                            <option value="like">{{ 'tag.filter.comparison.contains'|trans }}</option>
                            <option value="nlike">{{ 'tag.filter.comparison.not_contains'|trans }}</option>
                            <option value="startwith">{{ 'tag.filter.comparison.starts_with'|trans }}</option>
                            <option value="endwith">{{ 'tag.filter.comparison.ends_with'|trans }}</option>
                            <option value="eq">{{ 'tag.filter.comparison.exactly'|trans }}</option>
                            <option value="neq">{{ 'tag.filter.comparison.not_exactly'|trans }}</option>
                        </select>

                        <input type="text" class="form-control" data-tag-filter-term autocomplete="off">

                        {# The ONLY submitted field. `full_name`/`value` avoid hard-coding `fedaleForm`. #}
                        <input type="hidden" name="{{ form['name'].vars.full_name }}"
                               value="{{ form['name'].vars.value }}" data-tag-filter-value>
                    </div>
                </div>
                <div class="gv-modal__footer">
                    <button type="button" class="{{ gridview.cls('btn') }}"
                            data-action="tag-filter#close">{{ 'tag.filter.clear'|trans }}</button>
                    <button type="submit" class="{{ gridview.cls('btn.primary') }}">{{ 'tag.filter.apply'|trans }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
```

> **Note** — the comparison `<select>` and the text input carry **no `name`**, so they are
> never submitted. Only the hidden `data-tag-filter-value` field (named `fedaleForm[name]`)
> reaches the grid; the controller composes its value in Step 4.

---

## Step 3 — Map the comparison 1:1 with EasyAdmin

The controller prepends the selected operator to the term and writes `"<operator> <term>"`
into the hidden carrier. Using the **non-`i`** operator variants defers case-sensitivity to
the DB collation — the same behaviour EasyAdmin's string filter has:

| EasyAdmin comparison | submitted `fedaleForm[name]` | gridview operator |
|----------------------|--------------------------|-------------------|
| contains             | `like <term>`            | `LIKE %term%`     |
| doesn't contain      | `nlike <term>`           | `NOT LIKE %term%` |
| starts with          | `startwith <term>`       | `LIKE term%`      |
| ends with            | `endwith <term>`         | `LIKE %term`      |
| exactly              | `eq <term>`              | `= term`          |
| not exactly          | `neq <term>`             | `!= term`         |

Because we always send an explicit operator, the [client wildcard](index.md#text)
(`%foo`, `foo%`) is intentionally *not* triggered — the comparison is driven solely by the
`<select>`, 1:1 with EasyAdmin.

---

## Step 4 — The Stimulus controller

Drop this in `assets/controllers/`. The filename becomes the identifier
(`tag_filter_controller.js` → `tag-filter`) and it auto-registers — no `bootstrap.js`
edit needed.

```js
// assets/controllers/tag_filter_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['modal'];
    static OPERATORS = ['like', 'nlike', 'startwith', 'endwith', 'eq', 'neq'];
    static DEFAULT_OP = 'like';

    connect() {
        // Round-trip: split the server-rendered carrier value back into select + term,
        // then reflect the checkbox onto the controls.
        this._rows().forEach((row) => { this._parseInto(row); this._syncRow(row); });
    }

    open()  { this.modalTarget.classList.add('gv-open'); this.modalTarget.removeAttribute('aria-hidden'); }
    close() { this.modalTarget.classList.remove('gv-open'); this.modalTarget.setAttribute('aria-hidden', 'true'); }
    backdropClose(e) { if (e.target === this.modalTarget) this.close(); }

    toggle(e) { this._syncRow(e.target.closest('[data-tag-filter-row]')); }

    apply() {
        this._rows().forEach((row) => {
            const c = this._controls(row);
            const term = c.term ? c.term.value.trim() : '';
            const on = !!(c.checkbox && c.checkbox.checked) && term !== '';
            c.carrier.value = on ? `${c.comparison.value} ${term}` : '';
            c.carrier.disabled = !on;   // unchecked/empty → param dropped → filter cleared
        });
        // no preventDefault → the native GET submit carries the built params
    }

    _rows() { return Array.from(this.element.querySelectorAll('[data-tag-filter-row]')); }

    _controls(row) {
        return {
            checkbox:   row.querySelector('[data-tag-filter-toggle]'),
            comparison: row.querySelector('[data-tag-filter-comparison]'),
            term:       row.querySelector('[data-tag-filter-term]'),
            carrier:    row.querySelector('[data-tag-filter-value]'),
        };
    }

    _parseInto(row) {
        const c = this._controls(row);
        const raw = (c.carrier.value || '').trim();
        let op = this.constructor.DEFAULT_OP, rest = raw;
        const i = raw.indexOf(' ');
        if (i !== -1 && this.constructor.OPERATORS.includes(raw.slice(0, i).toLowerCase())) {
            op = raw.slice(0, i).toLowerCase();
            rest = raw.slice(i + 1).trim();
        }
        if (c.comparison) c.comparison.value = op;
        if (c.term) c.term.value = rest;
    }

    _syncRow(row) {
        const c = this._controls(row);
        const on = !!(c.checkbox && c.checkbox.checked);
        [c.comparison, c.term].forEach((el) => { if (el) el.disabled = !on; });
    }
}
```

---

## Step 5 — Translations (optional)

The template uses `tag.filter.*` keys. Add them to your message catalog, e.g.:

```php
'tag' => [
    'name' => 'Name',
    'filter' => [
        'button' => 'Filter', 'title' => 'Filters', 'apply' => 'Apply',
        'clear' => 'Clear', 'reset' => 'Reset filters',
        'comparison' => [
            'contains' => 'contains', 'not_contains' => "doesn't contain",
            'starts_with' => 'starts with', 'ends_with' => 'ends with',
            'exactly' => 'exactly', 'not_exactly' => 'not exactly',
        ],
    ],
],
```

---

## Done — and where to go next

You now have an EasyAdmin-grade filter UX — comparison operators, an active-count badge,
a reset — built on nothing but the public `fedaleForm` contract, the `text` operator prefixes
and the `[data-gridview]` style tokens. **No bundle code was touched.**

To extend it:

- **More fields:** repeat the `[data-tag-filter-row]` block and set `filterBar: true` on
  each column. The controller already loops over every row.
- **Other widgets:** render a [`boolean`](index.md#boolean), [`choice`](index.md#choice)
  or [`relation`](index.md#relation) filter the same way — they submit under `fedaleForm[…]`
  too, so the same Apply/round-trip logic applies (operators are text-only; selects just
  submit their value).
- **Server-side appliers:** if you need bespoke SQL, implement `search()` /
  `applyFilters()` in the repository — see
  [Applying filters in the repository](index.md#applying-filters-in-the-repository--applyfilters).
