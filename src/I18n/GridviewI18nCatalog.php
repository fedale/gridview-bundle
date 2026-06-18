<?php

namespace Fedale\GridviewBundle\I18n;

use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the browser-side translation catalog for the grid.
 *
 * The single source of truth stays in Symfony YAML (the bundle's
 * `GridviewBundle` domain for system strings + an app/tenant `client_domain`
 * for column labels and captions). This service exports the FULL catalog for
 * every enabled locale as JSON so the JS runtime can switch language instantly,
 * with no server roundtrip.
 */
final class GridviewI18nCatalog
{
    public function __construct(
        private TranslatorInterface $translator,
        private array $config,
    ) {
    }

    /** @return array<string, mixed> */
    private function i18n(): array
    {
        return $this->config['i18n'] ?? [];
    }

    /** @return string[] */
    public function getLocales(): array
    {
        $locales = $this->i18n()['locales'] ?? ['en'];

        return array_values(array_unique($locales));
    }

    public function getDefault(): string
    {
        return $this->i18n()['default'] ?? 'en';
    }

    public function getClientDomain(): string
    {
        return $this->i18n()['client_domain'] ?? 'Gridview';
    }

    /**
     * Translate a single key in the client domain using the current request
     * locale (for the server's first paint). Returns the key unchanged when no
     * translation exists, so callers can tell labels apart from raw strings.
     */
    public function clientTrans(string $key): string
    {
        return $this->translator->trans($key, [], $this->getClientDomain());
    }

    public function isClientLabel(string $key): bool
    {
        return $this->clientTrans($key) !== $key;
    }

    /**
     * Full catalog: locale => (key => translation), merging the system domain
     * and the client domain. MessageCatalogue::all() already folds in fallback
     * locales, so each locale map is complete.
     *
     * @return array<string, array<string, string>>
     */
    public function build(): array
    {
        $domains = ['GridviewBundle', $this->getClientDomain()];
        $out = [];

        foreach ($this->getLocales() as $locale) {
            $messages = [];

            if ($this->translator instanceof TranslatorBagInterface) {
                $catalogue = $this->translator->getCatalogue($locale);
                foreach ($domains as $domain) {
                    foreach ($catalogue->all($domain) as $key => $value) {
                        $messages[$key] = $value;
                    }
                }
            }

            $out[$locale] = $messages;
        }

        return $out;
    }

    public function toJson(): string
    {
        // Default flags escape "/" (so a value can never break out of the
        // <script> tag); keep unicode readable.
        return json_encode($this->build(), \JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * Client runtime config emitted alongside the catalog (data attribute).
     *
     * @return array<string, mixed>
     */
    public function clientConfig(): array
    {
        $i = $this->i18n();

        return [
            'default'         => $this->getDefault(),
            'locales'         => $this->getLocales(),
            'event'           => $i['external_event'] ?? 'gridview:set-locale',
            'eventKey'        => $i['external_event_key'] ?? 'locale',
            'observeHtmlLang' => (bool) ($i['observe_html_lang'] ?? true),
            'cookie'          => $i['cookie_name'] ?? 'gv_locale',
            'persistExternal' => (bool) ($i['persist_external'] ?? true),
        ];
    }

    public function langSwitcher(): bool
    {
        return (bool) ($this->i18n()['lang_switcher'] ?? false);
    }
}
