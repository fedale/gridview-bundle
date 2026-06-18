<?php

namespace Fedale\GridviewBundle\Twig;

use Fedale\GridviewBundle\I18n\GridviewI18nCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig glue for instant i18n: emits the browser catalog once and tags DOM nodes
 * with stable translation keys (`data-gv-i18n*`) so the JS runtime can swap text
 * client-side. Server-rendered text stays the first-paint value (cookie locale).
 */
class GridviewI18nExtension extends AbstractExtension
{
    private bool $catalogRendered = false;

    public function __construct(private GridviewI18nCatalog $catalog)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gv_i18n_catalog', [$this, 'renderCatalog'], ['is_safe' => ['html']]),
            new TwigFunction('gv_i18n', [$this, 'mark'], ['is_safe' => ['html']]),
            new TwigFunction('gv_i18n_attr', [$this, 'markAttr'], ['is_safe' => ['html']]),
            new TwigFunction('gv_header_label', [$this, 'headerLabel'], ['is_safe' => ['html']]),
            new TwigFunction('gv_client_text', [$this, 'clientText']),
            new TwigFunction('gv_client_label', [$this, 'isClientLabel']),
            new TwigFunction('gv_lang_switcher', [$this, 'langSwitcher']),
        ];
    }

    /** Emit the catalog <script> once per request (guarded for multi-grid pages). */
    public function renderCatalog(): string
    {
        if ($this->catalogRendered) {
            return '';
        }
        $this->catalogRendered = true;

        $config = $this->esc(json_encode($this->catalog->clientConfig(), \JSON_UNESCAPED_UNICODE) ?: '{}');

        return sprintf(
            '<script type="application/json" id="gridview-i18n-catalog" data-gv-config="%s">%s</script>',
            $config,
            $this->catalog->toJson()
        );
    }

    /** `data-gv-i18n="key"` — JS replaces the element's textContent. */
    public function mark(string $key): string
    {
        return sprintf('data-gv-i18n="%s"', $this->esc($key));
    }

    /** `data-gv-i18n-attr-<attr>="key"` — JS replaces the given attribute. */
    public function markAttr(string $key, string $attr): string
    {
        return sprintf('data-gv-i18n-attr-%s="%s"', $attr, $this->esc($key));
    }

    /**
     * Header label HTML: when the label is a key of the client domain, wrap the
     * translated text in a marked <span> (instant switch); otherwise return the
     * literal label escaped. Suitable as the `label` arg of renderHeader().
     */
    public function headerLabel(?string $label): string
    {
        if ($label === null || $label === '') {
            return '';
        }

        $text = $this->catalog->clientTrans($label);
        $escText = $this->esc($text);

        if ($text !== $label) {
            return sprintf('<span %s>%s</span>', $this->mark($label), $escText);
        }

        return $escText;
    }

    public function clientText(string $key): string
    {
        return $this->catalog->clientTrans($key);
    }

    public function isClientLabel(string $key): bool
    {
        return $this->catalog->isClientLabel($key);
    }

    public function langSwitcher(): bool
    {
        return $this->catalog->langSwitcher();
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES, 'UTF-8');
    }
}
