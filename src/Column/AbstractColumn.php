<?php

namespace Fedale\GridviewBundle\Column;

use Fedale\GridviewBundle\Contract\ColumnInterface;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use Fedale\GridviewBundle\Grid\Gridview;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;
use Twig\Environment;

abstract class AbstractColumn implements ColumnInterface
{
    /** @var callable|null */
    public $content;

    /**
     * Per-context rendering switch. Unlike `visible` (which only hides the
     * column with CSS, keeping it in the DOM and data), an inactive context
     * suppresses rendering there entirely. Set via `active => bool|array`:
     *   - true  (default): rendered everywhere;
     *   - false: rendered nowhere — the access-control kill-switch (no header,
     *     body cell, filter, export entry or CRUD form field);
     *   - array: granular `{inIndex, inView, inCreate, inUpdate}` (omitted keys
     *     default to true), mapped to the contexts below. A column inactive in
     *     `index` only is still registered (filterable, exportable, editable in
     *     forms) but produces no table cell and no "Columns" toggle entry.
     *
     * @var array{index: bool, view: bool, create: bool, update: bool}
     */
    protected array $active = ['index' => true, 'view' => true, 'create' => true, 'update' => true];
    protected bool $visible    = true;
    protected bool $sortable   = true;
    protected bool $filterable = true;
    protected bool $hidden     = false;
    protected bool $exportable = false;

    /**
     * Responsive-collapse priority, consumed only by the `gridview-responsive`
     * controller (active when the grid's `responsive` option is on). Semantics:
     *   - 0 (default): pinned — the column is never collapsed on narrow screens;
     *   - N > 0: collapsible. When the table overflows its container, columns are
     *     hidden into an expandable detail row in DESCENDING priority order, so a
     *     HIGHER number drops FIRST (least important). Ties drop right-to-left.
     * Structural columns (action/checkbox/serial) keep the default 0, so they
     * always stay visible.
     */
    protected int $priority = 0;

    /**
     * Normalized write-side control spec, or null when the column has no
     * editable control: ['type' => string, 'required' => bool, 'options' => array].
     */
    protected ?array $control = null;

    /** Whether this column appears in the delete-confirm recap (bool|array). */
    protected bool|array $showInDeleteConfirm = false;

    /** Whether this column is editable in the bulk "batch update" dialog. */
    protected bool $batchUpdate = false;

    /** Inline-editing config: false, true, or ['trigger' => 'click'|'dblclick']. */
    protected bool|array $editable = false;

    protected $value;

    protected Environment $twig;

    public function __construct(
        private Gridview $gridview,
        protected ?string $twigFilter = null,
        protected ?string $label = null,
        protected ?array $options = []
    ) {
        $this->initColumn();
    }

    protected function initColumn(): void {}

    public function renderFilter(FormBuilder $form): void
    {
        $form->add('name', TextType::class);
    }

    public function getAttribute(): ?string
    {
        return null;
    }

    public function render(mixed $data, int $_index): mixed
    {
        return $data[$this->content] ?? null;
    }

    public function getContent()
    {
        return $this->content;
    }

    public function setContent($content): void
    {
        $this->content = $content;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel($label): void
    {
        $this->label = $label;
    }

    public function getTwigFilter(): ?string
    {
        return $this->twigFilter;
    }

    public function setTwigFilter(string $twigFilter): void
    {
        $this->twigFilter = $twigFilter;
    }

    public function isActive(): bool
    {
        return \in_array(true, $this->active, true);
    }

    public function isActiveIn(string $context): bool
    {
        return $this->active[$context] ?? true;
    }

    /**
     * @param bool|array|\Closure $active
     */
    public function setActive($active): static
    {
        if ($active instanceof \Closure) {
            $active = $active();
        }

        if (\is_array($active)) {
            $this->active = [
                'index'  => (bool) ($active['inIndex']  ?? true),
                'view'   => (bool) ($active['inView']   ?? true),
                'create' => (bool) ($active['inCreate'] ?? true),
                'update' => (bool) ($active['inUpdate'] ?? true),
            ];
        } else {
            $flag = (bool) $active;
            $this->active = ['index' => $flag, 'view' => $flag, 'create' => $flag, 'update' => $flag];
        }

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    /**
     * @param bool|\Closure $visible
     */
    public function setVisible($visible): static
    {
        $this->visible = $visible instanceof \Closure ? (bool) $visible() : (bool) $visible;

        return $this;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    /**
     * Current sort state of this column, for rendering the header indicator:
     *   null   → not managed by the Sort object (no indicator)
     *   'none' → sortable but not the active sort
     *   'asc' / 'desc' → active sort direction
     */
    public function sortState(): ?string
    {
        return null;
    }

    /**
     * @param bool|\Closure $sortable
     */
    public function setSortable($sortable): static
    {
        $this->sortable = $sortable instanceof \Closure ? (bool) $sortable() : (bool) $sortable;

        return $this;
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    /**
     * @param bool|\Closure $filterable
     */
    public function setFilterable($filterable): static
    {
        $this->filterable = $filterable instanceof \Closure ? (bool) $filterable() : (bool) $filterable;

        return $this;
    }

    public function setGridview(Gridview $gridview): void  // satisfies ColumnInterface
    {
        $this->gridview = $gridview;
    }

    public function renderHeader($label): string
    {
        return $label;
    }

    public function getOptions(): array
    {
        return $this->options ?? [];
    }

    public function isToggleable(): bool
    {
        return true;
    }

    public function getFilter(): mixed
    {
        return null;
    }

    /**
     * Normalized control spec, or null when this column is not editable.
     *
     * @return array{type: string, required: bool, options: array}|null
     */
    public function getControl(): ?array
    {
        return $this->control;
    }

    public function setControl(?array $control): void
    {
        $this->control = $control;
    }

    /** @return bool|array */
    public function getShowInDeleteConfirm(): bool|array
    {
        return $this->showInDeleteConfirm;
    }

    public function setShowInDeleteConfirm(bool|array $showInDeleteConfirm): void
    {
        $this->showInDeleteConfirm = $showInDeleteConfirm;
    }

    public function isBatchUpdate(): bool
    {
        return $this->batchUpdate;
    }

    public function setBatchUpdate(bool $batchUpdate): void
    {
        $this->batchUpdate = $batchUpdate;
    }

    public function isExportable(): bool
    {
        return $this->exportable;
    }

    public function setExportable(bool $exportable): void
    {
        $this->exportable = $exportable;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    /** Inline-editable when `editable` is truthy AND the column has a control. */
    public function isEditable(): bool
    {
        return $this->editable !== false && $this->control !== null;
    }

    public function getEditableTrigger(): string
    {
        return \is_array($this->editable) ? ($this->editable['trigger'] ?? 'click') : 'click';
    }

    public function setEditable(bool|array $editable): void
    {
        $this->editable = $editable;
    }

    /**
     * Adds this column's editable field to the given form builder. Mirrors
     * {@see renderFilter()} for the write side. Columns with no control are
     * skipped silently; structural columns (action/checkbox/serial) keep the
     * default no-op by leaving $control null.
     */
    public function buildControl(FormBuilderInterface $form, ControlTypeRegistry $registry): void
    {
        if ($this->control === null) {
            return;
        }

        $attribute = $this->getAttribute();
        if ($attribute === null) {
            return;
        }

        $options = $this->control['options'] ?? [];
        $options['required'] ??= $this->control['required'] ?? false;
        if ($this->label !== null && !\array_key_exists('label', $options)) {
            $options['label'] = $this->label;
        }

        // An empty required text/textarea field submits as null and would break a
        // non-nullable typed setter (e.g. setCode(string)) during binding, before
        // validation runs. Coerce to '' so NotBlank can report it gracefully.
        if (($this->control['required'] ?? false) === true
            && \in_array($this->control['type'], ['text', 'html'], true)
            && !\array_key_exists('empty_data', $options)
        ) {
            $options['empty_data'] = '';
        }

        // Server-side validation: NotBlank for required fields (the `required`
        // option alone is only an HTML hint) plus any explicit constraints.
        $constraints = $options['constraints'] ?? [];
        if (($this->control['required'] ?? false) === true) {
            $message = $this->control['requiredMessage'] ?? null;
            $constraints[] = $message !== null ? new NotBlank(message: $message) : new NotBlank();
        }
        foreach ($this->control['constraints'] ?? [] as $constraint) {
            $constraints[] = $constraint;
        }
        if ($constraints !== []) {
            $options['constraints'] = $constraints;
        }

        // `media` controls (FileType) are virtual: the attribute is not a property
        // of the entity. The bundle owns phase 1 (receiving and validating the
        // upload); the host app's `upload` callable owns phase 2 (storing the bytes
        // and populating the real entity fields), invoked once the form is bound.
        if ($this->control['type'] === 'media') {
            $options['mapped'] = false;
            $form->add($attribute, $registry->get('media'), $options);

            $upload = $this->control['upload'] ?? null;
            if (\is_callable($upload)) {
                $form->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($attribute, $upload): void {
                    $form = $event->getForm();
                    if (!$form->has($attribute)) {
                        return;
                    }
                    $file = $form->get($attribute)->getData();
                    if ($file === null) {
                        return;
                    }
                    $upload($file, $event->getData(), $form);
                });
            }

            return;
        }

        $form->add($attribute, $registry->get($this->control['type']), $options);
    }
}
