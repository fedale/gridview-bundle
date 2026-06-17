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
        return <<<HTML
<div class="d-flex align-items-center gap-1">
  <input type="checkbox"
         data-gridview-selection-target="headerCheckbox"
         data-action="change->gridview-selection#togglePage">
  <div class="dropdown">
    <button class="btn btn-sm btn-link p-0 text-reset"
            data-bs-toggle="dropdown" type="button">&#x25BE;</button>
    <ul class="dropdown-menu">
      <li><button class="dropdown-item" type="button"
                  data-action="click->gridview-selection#selectAll">Seleziona tutti</button></li>
      <li><button class="dropdown-item" type="button"
                  data-action="click->gridview-selection#selectVisible">Seleziona visibili</button></li>
      <li><hr class="dropdown-divider"></li>
      <li><button class="dropdown-item" type="button"
                  data-action="click->gridview-selection#deselectAll">Deseleziona</button></li>
      <li><hr class="dropdown-divider"></li>
      <li><button class="dropdown-item" type="button"
                  data-action="click->gridview-selection#saveSelection">💾 Salva selezione…</button></li>
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
