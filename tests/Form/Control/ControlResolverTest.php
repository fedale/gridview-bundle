<?php

namespace Fedale\GridviewBundle\Tests\Form\Control;

use Fedale\GridviewBundle\Form\Control\ControlResolver;
use PHPUnit\Framework\TestCase;

class ControlResolverTest extends TestCase
{
    private ControlResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ControlResolver();
    }

    public function testExplicitTypeWins(): void
    {
        $resolved = $this->resolver->resolve(['type' => 'money', 'options' => ['currency' => 'EUR']], 'currency');

        $this->assertSame('money', $resolved['type']);
        $this->assertSame(['currency' => 'EUR'], $resolved['options']);
    }

    /**
     * Display data types that double as control types inherit when no explicit
     * control.type is given — the "same name on both axes" ergonomics.
     *
     * @dataProvider inheritableTypes
     */
    public function testDisplayTypeIsInheritedAsControlType(string $dataType): void
    {
        $this->assertSame($dataType, $this->resolver->resolve(true, $dataType)['type']);
    }

    public static function inheritableTypes(): array
    {
        return [['email'], ['url'], ['percent'], ['datetime']];
    }

    public function testCurrencyDisplayTypeFallsBackToTextNotCurrencyControl(): void
    {
        // `currency` display = formatted amount; its write twin is `money`, not the
        // CurrencyType code picker. So it must NOT inherit to a `currency` control.
        $this->assertSame('text', $this->resolver->resolve(true, 'currency')['type']);
    }

    public function testEnumControlRequiresClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An "enum" control requires options.class');

        $this->resolver->resolve(['type' => 'enum'], 'text');
    }

    public function testEnumControlAcceptsClass(): void
    {
        $resolved = $this->resolver->resolve(
            ['type' => 'enum', 'options' => ['class' => Priority::class]],
            'text'
        );

        $this->assertSame('enum', $resolved['type']);
        $this->assertSame(Priority::class, $resolved['options']['class']);
    }
}
