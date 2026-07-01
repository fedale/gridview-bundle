# Tutorial — Filter clear affordances: choose how to remove a filter

> **Level:** beginner · **Time:** ~10 min · **Bundle changes:** none
> ← Back to the [main documentation](index.md) · related: [Clearing a single column's filter](index.md#clearing-a-single-columns-filter--filterclear)

By default, an active filter is cleared by clicking the **funnel icon** next to the column
label. But the bundle lets you choose—per column or grid-wide—**how** users remove filters:

- **Funnel icon** (default): appears only when the filter is active
- **Inline ✕**: a button inside the filter input  
- **External chip**: a removable tag showing the column label + current value  
- **None**: no affordance (programmatic reset only)

You can **combine affordances** (e.g. funnel + chip), and override with **custom icons**.
This tutorial shows the most common patterns.

---

## The simplest: chip affordance (grid-wide default)

Set a grid-level default so all columns show a chip when filtered:

```php
// src/Controller/MyGridController.php

protected function viewConfig(): array
{
    return [
        'options' => [
            // All columns use 'chip' clear mode by default
            'filterControls' => ['clear' => 'chip'],
            // Place the chips in the layout (opt-in; not in the default layout)
            'layout' => ['header' => '{heading} {toolbar} {filterChips}'],
        ],
    ];
}
```

Now every filterable column that doesn't override `filter.clear` shows a removable chip
in the `{filterChips}` section (under the toolbar, in this example).

Chips render only when a filter is **actually applied** — no clutter when the grid is
clean. Click the **✕** to remove it; the filter clears and the grid re-submits.

---

## Mix affordances on a single column

Combine multiple clear methods on the same column:

```php
[
    'attribute' => 'name',
    'label'     => 'Name',
    'sortable'  => true,
    // Both funnel icon AND chip: users pick their favorite way to clear
    'filter'    => ['type' => 'text', 'clear' => ['header', 'chip']],
    'editable'  => true,
],
```

Now the column shows:
- A **funnel icon** in the header (when the filter is active)  
- An **external chip** (when the filter is active, in the `{filterChips}` section)

Both clear the same filter; users can choose which to use.

---

## All clear modes reference

| Mode | Renders as | When | Override per-column |
|------|-----------|------|------------------|
| `header` | Funnel icon next to the label | Always visible; lights up when active | `'filter' => ['type' => 'text', 'clear' => 'header']` |
| `input` | **✕** button inside the filter input | Always visible (when filter row is shown) | `'filter' => ['type' => 'text', 'clear' => 'input']` |
| `chip` | Removable tag (`Label: value` + **✕**) | Only when filter is applied | `'filter' => ['type' => 'text', 'clear' => 'chip']` |
| `none` | Nothing | — | `'filter' => ['type' => 'text', 'clear' => 'none']` |

---

## Custom icons

Replace the default icon on any affordance:

```php
[
    'attribute' => 'status',
    'filter'    => [
        'type' => 'choice',
        'clear' => [
            'mode'     => ['header', 'chip'],
            'icon'     => '<svg …>custom funnel</svg>',    // header clear icon
            'chipIcon' => '<svg …>custom close</svg>',     // chip close icon
        ],
    ],
],
```

Both `icon` (for header/input clear) and `chipIcon` (for chip close) accept raw
HTML/SVG strings.

---

## Inline clear button (completing a legacy feature)

The `input` mode shows an **✕** button inside the filter input. This mode was
partially implemented but never completed (the input didn't reserve space for it).

**Now it works:** the input reserves right padding so typed text never hides under
the button.

Enable it globally (legacy style):

```php
'filterControls' => [
    'inlineClear' => true,  // all columns show ✕ inside their inputs by default
],
```

Or per-column to override the grid-level default:

```php
[
    'attribute' => 'priority',
    'filter'    => ['type' => 'number', 'clear' => 'input'],  // only ✕ inside
],
```

---

## Practical patterns

### Pattern 1: Chip-only grid (modern UI)

```php
'options' => [
    'filterControls' => ['clear' => 'chip'],
    'layout'         => ['header' => '{heading} {toolbar} {filterChips}'],
],
```

All filters are removed via chips. Clean, scannable, and no clutter until a filter
is applied.

### Pattern 2: Funnel + chip (safe, accessible)

```php
'options' => [
    'filterControls' => ['clear' => ['header', 'chip']],
    'layout'         => ['header' => '{heading} {toolbar} {filterChips}'],
],
```

Power users have both options; the funnel is always there, the chip is a bonus
affordance.

### Pattern 3: Disable clear on a sensitive field

```php
[
    'attribute' => 'archived',
    'filter'    => ['type' => 'boolean', 'clear' => 'none'],  // no affordance
],
```

Users can't accidentally click to clear; they must reset via the global "Reset all"
button.

### Pattern 4: Custom icon for a domain field

```php
[
    'attribute' => 'risk_level',
    'filter'    => [
        'type' => 'choice',
        'clear' => [
            'mode'     => 'header',
            'icon'     => '<svg class="risk-icon">…</svg>',
        ],
    ],
],
```

The funnel is replaced with your domain icon, making the filter feel more integrated.

---

## Placement of `{filterChips}`

The `{filterChips}` token is opt-in and can go anywhere in the layout:

```php
// Under the toolbar (most common)
'layout' => ['header' => '{heading} {toolbar} {filterChips}'],

// Or in a custom region (between toolbar and grid)
'layout' => [
    'shell'     => '{header} {dataview} {footer}',
    'header'    => '{heading} {toolbar} {filterChips}',
],

// Or even in the footer
'layout' => ['footer' => '{filterChips} {pagination}'],
```

Chips render **only when filters are active**; an empty chips section collapses to
nothing, so placement is flexible.

---

## How it works under the hood

All clear affordances use the same generic Stimulus action: `gridview-filter#clearFilter`.
When you click a funnel, an **✕**, or a chip **✕**, the grid finds the corresponding
input, clears its value, and re-submits the form. **No custom JS needed.**

The `{filterChips}` section is rendered server-side, only for columns with:
1. `filter.clear` mode includes `'chip'`
2. The filter is **actually applied** in the current request

This means chips are only DOM if they're relevant — keeping markup lean.

---

## Resolution order for defaults

When a column doesn't specify `filter.clear`, the bundle uses this order:

1. **`filter.clear`** (explicit per-column) — always wins if present
2. **`filterControls.clear`** (grid-level default) — second priority
3. **Fallback**: `['header']` + `'input'` if `filterControls.inlineClear` is `true`

This lets you set a grid-wide style and override only the exceptions:

```php
'options' => [
    'filterControls' => ['clear' => 'chip'],  // grid default: chip mode
],

// buildColumns():
[
    'attribute' => 'name',
    'filter'    => ['type' => 'text'],        // inherits grid default: 'chip'
],
[
    'attribute' => 'archived',
    'filter'    => ['type' => 'boolean', 'clear' => 'none'],  // override: no affordance
],
```

---

## Next steps

- Read the [Filtering & Search section](index.md#filtering--search) for the full API reference
- See the **CategoryController** in `gridview-demo` for a working example with chips
- Combine with the [custom filter modal tutorial](tutorial-custom-filter-modal.md) if you
  want a completely custom filter UI alongside standard columns
