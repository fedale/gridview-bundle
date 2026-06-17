<?php

namespace Fedale\GridviewBundle\Tests\Support;

use Fedale\GridviewBundle\Contract\ColumnInterface;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use Fedale\GridviewBundle\Grid\Gridview;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Minimal {@see ColumnInterface} test double for DetailView tests. The cell
 * value is computed by a caller-supplied closure `fn($model, $index)`.
 */
class FakeDetailColumn implements ColumnInterface
{
    /** @var callable */
    private $renderer;

    public function __construct(
        private ?string $attribute,
        private ?string $label = null,
        ?callable $renderer = null,
        private bool $visible = true,
        private ?string $twigFilter = null,
    ) {
        $this->renderer = $renderer ?? static fn ($model, $index) => $model->data[$attribute] ?? null;
    }

    public function isVisible(): bool { return $this->visible; }
    public function isFilterable(): bool { return false; }
    public function isSortable(): bool { return false; }
    public function isToggleable(): bool { return true; }
    public function setGridview(Gridview $gridview): void {}
    public function getAttribute(): ?string { return $this->attribute; }
    public function getLabel(): ?string { return $this->label; }
    public function getTwigFilter(): ?string { return $this->twigFilter; }
    public function getControl(): ?array { return null; }
    public function buildControl(FormBuilderInterface $form, ControlTypeRegistry $registry): void {}
    public function getShowInDeleteConfirm(): bool|array { return false; }
    public function isBatchUpdate(): bool { return false; }
    public function isExportable(): bool { return true; }
    public function isEditable(): bool { return false; }
    public function getEditableTrigger(): string { return 'dblclick'; }
    public function render(mixed $model, int $index): mixed { return ($this->renderer)($model, $index); }
    public function renderHeader(mixed $label): string { return (string) $label; }

    /** Read by the template (`column.options`); not part of the interface. */
    public function getOptions(): array { return []; }
}
