<?php

namespace Fedale\GridviewBundle\Tests\Filter;

use Fedale\GridviewBundle\Filter\FilterDateType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;

/**
 * The date filter's defaults suit any host: placeholders in the page's
 * language, and every date selectable unless the column bounds them.
 */
class FilterDateTypeTest extends TestCase
{
    private function field(array $options = []): FormInterface
    {
        return Forms::createFormFactory()
            ->createBuilder()
            ->add('born', FilterDateType::class, $options)
            ->getForm()
            ->get('born');
    }

    public function testThePlaceholdersAreKeysOfTheBundleCatalog(): void
    {
        $view = $this->field()->createView();

        foreach (['from' => 'filter.date_from', 'to' => 'filter.date_to'] as $input => $key) {
            $this->assertSame($key, $view[$input]->vars['attr']['placeholder']);
            $this->assertSame('GridviewBundle', $view[$input]->vars['translation_domain']);
        }
        foreach (['en' => ['From', 'To'], 'it' => ['Da', 'A']] as $locale => [$from, $to]) {
            // A flat catalog: one `key: "text"` per line (symfony/yaml is no dependency).
            $catalog = (string) file_get_contents(\dirname(__DIR__, 2).'/translations/GridviewBundle.'.$locale.'.yaml');
            $this->assertStringContainsString("\nfilter.date_from: \"$from\"\n", $catalog, $locale);
            $this->assertStringContainsString("\nfilter.date_to: \"$to\"\n", $catalog, $locale);
        }
    }

    public function testAnExplicitPlaceholderStillWins(): void
    {
        $view = $this->field(['from_placeholder' => 'Born after'])->createView();

        $this->assertSame('Born after', $view['from']->vars['attr']['placeholder']);
    }

    public function testAnyDateIsSelectableUnlessTheColumnBoundsIt(): void
    {
        $options = fn (array $field): array => json_decode($this->field($field)->createView()->vars['attr']['data-gridview-date-filter-options-value'], true);

        $this->assertSame(['mode' => 'range', 'altFormat' => 'd/m/Y'], $options([]));
        $this->assertSame('2020-01-01', $options(['client_options' => ['minDate' => '2020-01-01']])['minDate']);
    }
}
