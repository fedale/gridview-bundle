<?php
namespace Fedale\GridviewBundle\Column;

use Fedale\GridviewBundle\Grid\Gridview;

class CheckboxColumn extends AbstractColumn
{
    public function __construct(
        private Gridview $gridview,
        protected ?string $twigFilter = null,
        protected ?string $label = null,
        protected ?array $options = []
    ) {
        $this->twigFilter = 'raw';
        $this->filterable = false;
        $this->sortable   = false;
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function getAttribute(): string
    {
        return '_selection';
    }

    public function renderHeader($label): string
    {
        // Self-contained dropdown (gv-dropdown), no Bootstrap JS. The menu items
        // dispatch to the gridview-selection controller on the grid root; the
        // gridview-dropdown controller only handles open/close. Labels carry
        // data-gv-i18n keys so the client i18n runtime localizes them (English
        // text below is the fallback shown before the runtime applies).
        return <<<HTML
<div class="gv-select-header">
  <input type="checkbox"
         data-gridview-selection-target="headerCheckbox"
         data-action="change->gridview-selection#togglePage">
  <div class="gv-dropdown" data-controller="gridview-dropdown">
    <button class="gv-caret-toggle" type="button"
            data-action="click->gridview-dropdown#toggle"
            data-gv-i18n-attr-aria-label="selection.menu" aria-label="Selection menu"
            aria-expanded="false">&#x25BE;</button>
    <ul class="gv-dropdown-menu">
      <li><button class="gv-dropdown-item" type="button"
                  data-action="click->gridview-selection#selectAll"
                  data-gv-i18n="selection.select_all">Select all</button></li>
      <li><button class="gv-dropdown-item" type="button"
                  data-action="click->gridview-selection#selectVisible"
                  data-gv-i18n="selection.select_visible">Select visible</button></li>
      <li><hr class="gv-dropdown-divider"></li>
      <li><button class="gv-dropdown-item" type="button"
                  data-action="click->gridview-selection#deselectAll"
                  data-gv-i18n="selection.deselect">Deselect</button></li>
      <li><hr class="gv-dropdown-divider"></li>
      <li><button class="gv-dropdown-item" type="button"
                  data-action="click->gridview-selection#saveSelection"><span class="gv-icon-sm" aria-hidden="true">💾</span> <span data-gv-i18n="selection.save">Save selection…</span></button></li>
      <span data-gridview-selection-target="savedList"></span>
    </ul>
  </div>
</div>
HTML;
    }

    public function render($row, $index): string
    {
        $id = htmlspecialchars((string)($row->data['id'] ?? $index));
        return sprintf(
            '<input type="checkbox" data-gridview-selection-target="checkbox" data-action="change->gridview-selection#toggle" value="%s">',
            $id
        );
    }
}
