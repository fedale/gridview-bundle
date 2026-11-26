<?php

namespace Fedale\GridviewBundle\Tests\Translations;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * The GridviewBundle catalogs: every locale holds the same keys, and every key a template
 * translates is in them. A key missing from a catalog shows as the key itself on the page.
 */
class TranslationCatalogsTest extends TestCase
{
    private const DIR = __DIR__.'/../../translations';

    public function testEveryLocaleHasTheSameKeys(): void
    {
        $english = array_keys($this->catalog('en'));
        sort($english);

        foreach ($this->locales() as $locale) {
            $keys = array_keys($this->catalog($locale));
            sort($keys);
            self::assertSame($english, $keys, sprintf('The %s catalog differs from the English one.', $locale));
        }
    }

    public function testEveryKeyATemplateTranslatesExists(): void
    {
        $english = $this->catalog('en');
        $missing = [];
        $templates = (new Finder())->files()->in(__DIR__.'/../../templates')->name('*.twig');
        foreach ($templates as $template) {
            preg_match_all("/'([A-Za-z0-9_.]+)'\\|trans\\(\\{[^}]*\\}, 'GridviewBundle'\\)/", $template->getContents(), $matches);
            foreach ($matches[1] as $key) {
                if (!array_key_exists($key, $english)) {
                    $missing[] = $template->getRelativePathname().': '.$key;
                }
            }
        }

        self::assertSame([], $missing);
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        $locales = [];
        foreach (glob(self::DIR.'/GridviewBundle.*.yaml') ?: [] as $file) {
            $locales[] = explode('.', basename($file))[1];
        }

        return $locales;
    }

    /**
     * @return array<string, mixed> the catalog flattened to dotted keys
     */
    private function catalog(string $locale): array
    {
        $data = Yaml::parseFile(sprintf('%s/GridviewBundle.%s.yaml', self::DIR, $locale));

        return $this->flatten(is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix.$key.'.');
                continue;
            }
            $flat[$prefix.$key] = $value;
        }

        return $flat;
    }
}
