<?php

namespace Fedale\GridviewBundle\Column;

use Fedale\GridviewBundle\Contract\ActionButtonInterface;
use Fedale\GridviewBundle\Grid\Gridview;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class ActionColumn extends AbstractColumn
{
    public string $layout = '{view} {edit} {delete}';

    /** @var array<string, ActionButtonInterface> */
    private array $buttonMap = [];

    private UrlGeneratorInterface $urlGenerator;

    public function __construct(
        private Gridview $gridview,
        private string $attribute,
        protected ?string $twigFilter = null,
        protected ?string $label = null,
        protected ?array $options = [],
        private ?AuthorizationCheckerInterface $authChecker = null,
    ) {
        if (null === $this->label) {
            $this->setLabel($attribute);
        }
        $this->setTwigFilter('raw');
        $this->initDefaultButtons();
    }

    public function initColumn(): void
    {
        $this->label = 'Actions';
    }

    public function setRouter(UrlGeneratorInterface $urlGenerator): void
    {
        $this->urlGenerator = $urlGenerator;
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function getAttribute(): string
    {
        return $this->attribute;
    }

    public function setLayout(string $layout): void
    {
        $this->layout = $layout;
    }

    /**
     * Merge button definitions into the buttonMap.
     * Each value may be an ActionButtonInterface, a callable, a string, or an array
     * with keys: content (string|callable), roles (string[]), visible (bool|callable).
     *
     * @param array<string, ActionButtonInterface|callable|string|array> $buttons
     */
    public function setButtons(array $buttons): void
    {
        foreach ($buttons as $name => $spec) {
            $this->buttonMap[$name] = $this->normalizeButton($spec);
        }
    }

    public function render(mixed $model, int $index): mixed
    {
        $data = $model->data ?? $model;

        preg_match_all('/\{([\w-]+)\}/', $this->layout, $matches);

        $parts = [];
        foreach ($matches[1] as $token) {
            $button = $this->buttonMap[$token] ?? null;
            if ($button === null) {
                continue;
            }
            if (!$button->isVisible($data, $index)) {
                continue;
            }
            $roles = $button->getRoles();
            if ($roles !== [] && $this->authChecker !== null) {
                $granted = false;
                foreach ($roles as $role) {
                    if ($this->authChecker->isGranted($role)) {
                        $granted = true;
                        break;
                    }
                }
                if (!$granted) {
                    continue;
                }
            }
            $parts[] = $button->render($data, $index);
        }

        return implode(' ', $parts);
    }

    public function renderHeader(mixed $_label): string
    {
        return $this->label ?? '';
    }

    private function initDefaultButtons(): void
    {
        $this->buttonMap['view'] = new ActionButton(
            '<a href="#" title="View"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></a>'
        );
        $this->buttonMap['edit'] = new ActionButton(
            '<a href="#" title="Edit"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a>'
        );
        $this->buttonMap['delete'] = new ActionButton(
            '<a href="#" title="Delete"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc3545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></a>'
        );
    }

    private function normalizeButton(mixed $spec): ActionButtonInterface
    {
        if ($spec instanceof ActionButtonInterface) {
            return $spec;
        }
        if (\is_callable($spec)) {
            return new ActionButton(\Closure::fromCallable($spec));
        }
        if (\is_string($spec)) {
            return new ActionButton($spec);
        }
        if (\is_array($spec)) {
            return new ActionButton(
                $spec['content'] ?? '',
                $spec['roles'] ?? [],
                $spec['visible'] ?? true,
            );
        }
        throw new \InvalidArgumentException(sprintf(
            'Invalid button spec: expected ActionButtonInterface, callable, string or array, got "%s".',
            get_debug_type($spec)
        ));
    }
}
