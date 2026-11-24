<?php

namespace Fedale\GridviewBundle\Tests\Form\Control;

use Fedale\GridviewBundle\Form\Control\ChoiceDisplay;
use Fedale\GridviewBundle\Form\Control\ControlResolver;
use Fedale\GridviewBundle\Form\Control\ControlType;
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
        return [
            ['email'], ['url'], ['percent'], ['datetime'], ['time'],
            ['money'], ['currency'], ['color'], ['country'], ['language'],
            ['locale'], ['timezone'], ['hidden'], ['tel'],
        ];
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

    public function testTheTypeAndTheDisplayMayBeEnums(): void
    {
        $resolved = $this->resolver->resolve(['type' => ControlType::Choice, 'display' => ChoiceDisplay::Segmented], 'text');

        $this->assertSame('choice', $resolved['type']);
        $this->assertSame('segmented', $resolved['display']);
        $this->assertSame('textarea', $this->resolver->resolve(ControlType::Textarea, 'text')['type']);
    }

    public function testTheSettingsDefaultToNoneAndValidation(): void
    {
        $resolved = $this->resolver->resolve(['type' => 'text'], 'text');

        $this->assertNull($resolved['display']);
        $this->assertNull($resolved['maxLength']);
        $this->assertNull($resolved['scale']);
        $this->assertFalse($resolved['confirm']);
        $this->assertNull($resolved['minLength']);
        $this->assertTrue($resolved['validate']);
    }

    /**
     * @dataProvider invalidSettings
     *
     * @param array<string, mixed> $control
     */
    public function testAnInvalidSettingIsRefused(array $control, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->resolver->resolve($control, 'text');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'a display on a text' => [['type' => 'text', 'display' => 'switch'], 'A "text" control takes no display; only boolean and choice do.'];
        yield 'a display of another control' => [['type' => 'boolean', 'display' => 'segmented'], 'The display of a "boolean" control must be one of: checkbox, switch.'];
        yield 'a maxLength on a number' => [['type' => 'number', 'maxLength' => 3], 'A "number" control takes no "maxLength"'];
        yield 'a scale on an integer' => [['type' => 'integer', 'scale' => 2], 'A "integer" control takes no "scale"'];
        yield 'a negative scale' => [['type' => 'number', 'scale' => -1], 'The "scale" of a "number" control has an invalid value: int.'];
        yield 'a confirmation of a text' => [['type' => 'text', 'confirm' => true], 'A "text" control takes no "confirm"; only password do.'];
        yield 'a minLength of an email' => [['type' => 'email', 'minLength' => 8], 'A "email" control takes no "minLength"'];
        yield 'a meter given as a string' => [['type' => 'password', 'strengthMeter' => 'yes'], 'The "strengthMeter" of a "password" control has an invalid value: string.'];
        yield 'a text bound' => [['type' => 'integer', 'min' => '1'], 'The "min" of a "integer" control has an invalid value: string.'];
    }
}
