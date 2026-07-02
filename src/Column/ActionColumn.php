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

    public function getKind(): string
    {
        return 'action';
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

    public function renderHeader(mixed $label): string
    {
        // Honor the (already translated / i18n-tagged) label computed by the
        // template; fall back to the column's own label.
        return ($label !== null && $label !== '') ? (string) $label : ($this->label ?? '');
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
