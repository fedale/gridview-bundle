<?php

namespace Fedale\GridviewBundle\Tests\Form\Control;

use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\CurrencyType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\LanguageType;
use Symfony\Component\Form\Extension\Core\Type\LocaleType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

class ControlTypeRegistryTest extends TestCase
{
    /**
     * Every control type added by batches 1-3 maps to the matching Symfony
     * FormType, so the column gets that type's rendering and data transformer
     * for free. `money` and `currency` are intentionally distinct entries.
     *
     * @dataProvider newControlTypes
     */
    public function testControlTypeMapsToFormType(string $type, string $expectedClass): void
    {
        $registry = new ControlTypeRegistry();

        $this->assertTrue($registry->has($type));
        $this->assertSame($expectedClass, $registry->get($type));
    }

    public static function newControlTypes(): array
    {
        return [
            'email'    => ['email', EmailType::class],
            'url'      => ['url', UrlType::class],
            'password' => ['password', PasswordType::class],
            'color'    => ['color', ColorType::class],
            'integer'  => ['integer', IntegerType::class],
            'money'    => ['money', MoneyType::class],
            'percent'  => ['percent', PercentType::class],
            'range'    => ['range', RangeType::class],
            'datetime' => ['datetime', DateTimeType::class],
            'time'     => ['time', TimeType::class],
            'enum'     => ['enum', EnumType::class],
            'country'  => ['country', CountryType::class],
            'language' => ['language', LanguageType::class],
            'locale'   => ['locale', LocaleType::class],
            'timezone' => ['timezone', TimezoneType::class],
            'currency' => ['currency', CurrencyType::class],
        ];
    }

    public function testMoneyAndCurrencyAreSeparateEntries(): void
    {
        $registry = new ControlTypeRegistry();

        $this->assertSame(MoneyType::class, $registry->get('money'), 'money = amount');
        $this->assertSame(CurrencyType::class, $registry->get('currency'), 'currency = code picker');
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown control type "nope"');

        (new ControlTypeRegistry())->get('nope');
    }
}
