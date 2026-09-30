<?php

namespace Fedale\GridviewBundle\Tests\Filter;

use Fedale\GridviewBundle\Filter\FilterChoiceType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

/**
 * A filter is never mandatory: the choice filter must offer the empty option
 * that clears it, otherwise picking a value is a one-way door.
 */
class FilterChoiceTypeTest extends TestCase
{
    private function field(array $options = []): \Symfony\Component\Form\FormInterface
    {
        return Forms::createFormFactory()
            ->createBuilder()
            ->add('status', FilterChoiceType::class, $options + ['choices' => ['Draft' => 'draft', 'Published' => 'published']])
            ->getForm()
            ->get('status');
    }

    public function testTheFilterIsOptionalAndOffersAnEmptyOption(): void
    {
        $field = $this->field();

        $this->assertFalse($field->getConfig()->getOption('required'));
        $this->assertSame('', $field->getConfig()->getOption('placeholder'));
        $this->assertSame('', $field->createView()->vars['placeholder']);
    }

    public function testTheDeclaredChoicesReachTheView(): void
    {
        $choices = $this->field()->createView()->vars['choices'];

        $this->assertSame(['draft', 'published'], array_map(static fn($c) => $c->value, $choices));
        $this->assertSame(['Draft', 'Published'], array_map(static fn($c) => $c->label, $choices));
    }

    public function testAnExplicitPlaceholderStillWins(): void
    {
        $field = $this->field(['placeholder' => 'any.status']);

        $this->assertSame('any.status', $field->createView()->vars['placeholder']);
    }
}
