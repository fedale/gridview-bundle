<?php

namespace Fedale\GridviewBundle\Tests\Column\Type;

use Fedale\GridviewBundle\Column\Type\BadgeType;
use Fedale\GridviewBundle\Column\Type\ColorType;
use Fedale\GridviewBundle\Column\Type\ColumnTypeInterface;
use Fedale\GridviewBundle\Column\Type\ColumnTypeRegistry;
use Fedale\GridviewBundle\Column\Type\CountryType;
use Fedale\GridviewBundle\Column\Type\CurrencyType;
use Fedale\GridviewBundle\Column\Type\DateType;
use Fedale\GridviewBundle\Column\Type\DatetimeType;
use Fedale\GridviewBundle\Column\Type\HiddenType;
use Fedale\GridviewBundle\Column\Type\LanguageType;
use Fedale\GridviewBundle\Column\Type\LocaleType;
use Fedale\GridviewBundle\Column\Type\MediaType;
use Fedale\GridviewBundle\Column\Type\MoneyType;
use Fedale\GridviewBundle\Column\Type\NumberType;
use Fedale\GridviewBundle\Column\Type\PercentType;
use Fedale\GridviewBundle\Column\Type\SelectType;
use Fedale\GridviewBundle\Column\Type\TelType;
use Fedale\GridviewBundle\Column\Type\TextType;
use Fedale\GridviewBundle\Column\Type\TimeType;
use Fedale\GridviewBundle\Column\Type\TimezoneType;
use Fedale\GridviewBundle\Contract\ColumnInterface;
use PHPUnit\Framework\TestCase;
use Twig\Markup;

class ColumnTypeTest extends TestCase
{
    private ?string $savedLocale = null;

    protected function setUp(): void
    {
        $this->savedLocale = \Locale::getDefault();
    }

    protected function tearDown(): void
    {
        \Locale::setDefault($this->savedLocale);
    }

    private function column(string $attribute = 'x'): ColumnInterface
    {
        $column = $this->createStub(ColumnInterface::class);
        $column->method('getAttribute')->willReturn($attribute);

        return $column;
    }

    /** @param array<string,mixed> $options */
    private function pipeline(ColumnTypeInterface $type, array $data, array $options = []): mixed
    {
        $column  = $this->column(array_key_first($data) ?? 'x');
        $options = array_merge($type->getDefaultOptions(), $options);
        $raw     = $type->getRawValue($data, $column);
        $display = $type->format($raw, $options, $column);

        return $type->render($display, $options, $column);
    }

    public function testRegistryHasBuiltinsAndAliases(): void
    {
        $registry = ColumnTypeRegistry::withBuiltins();

        $this->assertTrue($registry->has('text'));
        $this->assertTrue($registry->has('money'));
        $this->assertTrue($registry->has('currency'));
        $this->assertTrue($registry->has('rating'));
        // aliases
        $this->assertInstanceOf(SelectType::class, $registry->get('choice'));
        $this->assertInstanceOf(TextType::class, $registry->get('data'));
    }

    public function testInheritanceMirrorsExtends(): void
    {
        $registry = ColumnTypeRegistry::withBuiltins();

        $this->assertInstanceOf(NumberType::class, $registry->get('money'));
        $this->assertInstanceOf(SelectType::class, $registry->get('rating'));
        $this->assertInstanceOf(SelectType::class, $registry->get('badge'));
        $this->assertSame('number', $registry->get('money')->getParent());
        $this->assertSame('select', $registry->get('rating')->getParent());
    }

    public function testMoneyInheritsNumberFilterAndOwnControl(): void
    {
        $money = new MoneyType();

        $this->assertSame('number', $money->inferFilterType());
        $this->assertSame('money', $money->inferControlType());
    }

    /**
     * `currency` (ISO code, e.g. "EUR") is a distinct concept from `money` (a
     * formatted amount) — mirrors Symfony's own MoneyType/CurrencyType split and
     * EasyAdmin's MoneyField/CurrencyField.
     */
    public function testCurrencyIsTextLikeWithItsOwnControl(): void
    {
        $currency = new CurrencyType();

        $this->assertSame('text', $currency->inferFilterType());
        $this->assertSame('currency', $currency->inferControlType());
    }

    public function testCurrencyRendersBareCodeWithoutIntl(): void
    {
        $type = new class extends CurrencyType {
            protected function intlAvailable(): bool
            {
                return false;
            }
        };

        $out = (string) $this->pipeline($type, ['x' => 'eur'], []);

        $this->assertSame('EUR', $out);
    }

    public function testCurrencyRendersLocalizedNameWithIntl(): void
    {
        $type = new CurrencyType();

        \Locale::setDefault('en_US');
        $out = (string) $this->pipeline($type, ['x' => 'EUR'], []);

        $this->assertStringContainsString('Euro', $out);
        $this->assertStringContainsString('EUR', $out);
    }

    public function testCurrencyShowNameFalseKeepsBareCode(): void
    {
        $type = new CurrencyType();
        $out  = (string) $this->pipeline($type, ['x' => 'USD'], ['showName' => false]);

        $this->assertSame('USD', $out);
    }

    public function testNumberFormatsAndWrapsRightAligned(): void
    {
        $type = new NumberType();
        $out  = $this->pipeline($type, ['x' => 1234.56], ['decimals' => 2, 'locale' => 'it_IT']);

        $this->assertInstanceOf(Markup::class, $out);
        $this->assertStringContainsString('gv-num', (string) $out);
        $this->assertStringContainsString('1.234,56', (string) $out);
    }

    public function testNumberFollowsAmbientDefaultLocaleWhenUnset(): void
    {
        $type = new NumberType();

        \Locale::setDefault('en_US');
        $en = (string) $this->pipeline($type, ['x' => 1234.56], ['decimals' => 2]);
        $this->assertStringContainsString('1,234.56', $en);

        \Locale::setDefault('it_IT');
        $it = (string) $this->pipeline($type, ['x' => 1234.56], ['decimals' => 2]);
        $this->assertStringContainsString('1.234,56', $it);
    }

    public function testNumberExplicitSeparatorsBypassLocale(): void
    {
        $type = new NumberType();
        $out  = (string) $this->pipeline($type, ['x' => 1234.56], [
            'decimals'     => 2,
            'locale'       => 'en_US',
            'decimalSep'   => ',',
            'thousandsSep' => '.',
        ]);

        $this->assertStringContainsString('1.234,56', $out);
    }

    public function testNumberNoIntlFallbackUsesLocaleSeparatorTable(): void
    {
        $type = new class extends NumberType {
            protected function intlAvailable(): bool
            {
                return false;
            }
        };

        \Locale::setDefault('en_US');
        $en = (string) $this->pipeline($type, ['x' => 1234.56], ['decimals' => 2]);
        $this->assertStringContainsString('1,234.56', $en);

        \Locale::setDefault('it_IT');
        $it = (string) $this->pipeline($type, ['x' => 1234.56], ['decimals' => 2]);
        $this->assertStringContainsString('1.234,56', $it);

        // Unknown locale degrades to the en-style pair, not it-IT.
        \Locale::setDefault('ja_JP');
        $ja = (string) $this->pipeline($type, ['x' => 1234.56], ['decimals' => 2]);
        $this->assertStringContainsString('1,234.56', $ja);
    }

    public function testPercentDoesNotForceItalianSeparatorsWhenUnset(): void
    {
        $type = new PercentType();

        \Locale::setDefault('en_US');
        $out = (string) $this->pipeline($type, ['x' => 1234.0], ['decimals' => 0]);

        // en_US thousands separator (",") proves the it-IT-hardcoded default
        // ("1.234") is no longer silently forced.
        $this->assertStringContainsString('1,234%', $out);
    }

    public function testMoneyDoesNotForceItalianSeparatorsWhenUnset(): void
    {
        $type = new MoneyType();

        \Locale::setDefault('en_US');
        $out = (string) $this->pipeline($type, ['x' => 1234.56], []);

        // en_US EUR formatting, not the it-IT-style "1.234,56" the old
        // hardcoded default would have silently forced.
        $this->assertStringContainsString('1,234.56', $out);
    }

    public function testMoneyNoIntlFallbackFollowsLocale(): void
    {
        $type = new class extends MoneyType {
            protected function intlAvailable(): bool
            {
                return false;
            }
        };

        \Locale::setDefault('en_US');
        $out = (string) $this->pipeline($type, ['x' => 1234.56], []);

        $this->assertStringContainsString('1,234.56', $out);
        $this->assertStringContainsString('€', $out);
    }

    public function testDateExplicitPatternBypassesLocale(): void
    {
        $type = new DateType();
        \Locale::setDefault('fr_FR');

        $out = $this->pipeline($type, ['x' => '2026-07-11'], ['pattern' => 'Y-m-d']);

        $this->assertSame('2026-07-11', $out);
    }

    public function testDateFollowsAmbientDefaultLocaleWhenUnset(): void
    {
        $type = new DateType();

        \Locale::setDefault('en_US');
        $this->assertSame('7/11/2026', $this->pipeline($type, ['x' => '2026-07-11'], []));

        \Locale::setDefault('it_IT');
        $this->assertSame('11/07/2026', $this->pipeline($type, ['x' => '2026-07-11'], []));

        // fr_FR's SHORT pattern is already 4-digit; confirm the forcing regex
        // does not double it up (e.g. into "yyyy" -> "yyyyyy").
        \Locale::setDefault('fr_FR');
        $this->assertSame('11/07/2026', $this->pipeline($type, ['x' => '2026-07-11'], []));
    }

    public function testDatetimeFollowsAmbientDefaultLocaleWhenUnset(): void
    {
        $type = new DatetimeType();
        \Locale::setDefault('it_IT');

        $this->assertSame('11/07/2026, 15:30', $this->pipeline($type, ['x' => '2026-07-11 15:30:00'], []));
    }

    public function testDateNoIntlFallbackUsesFixedPattern(): void
    {
        $type = new class extends DateType {
            protected function intlAvailable(): bool
            {
                return false;
            }
        };

        \Locale::setDefault('en_US');
        $this->assertSame('11/07/2026', $this->pipeline($type, ['x' => '2026-07-11'], []));
    }

    public function testTextIsPlainPassthroughForDownstreamEscaping(): void
    {
        $type = new TextType();
        $out  = $this->pipeline($type, ['x' => '<b>hi</b>']);

        // Not Markup: Twig escapes it on output (no XSS).
        $this->assertNotInstanceOf(Markup::class, $out);
        $this->assertSame('<b>hi</b>', $out);
    }

    public function testBadgeKeepsRawForColourLookup(): void
    {
        $type = new BadgeType();
        $out  = (string) $this->pipeline($type, ['x' => 'OPEN'], [
            'choices' => ['Aperto' => 'OPEN'],
            'colors'  => ['OPEN' => '#0a0'],
        ]);

        $this->assertStringContainsString('gv-badge--open', $out);
        $this->assertStringContainsString('background-color:#0a0', $out);
        $this->assertStringContainsString('Aperto', $out);
    }

    public function testMediaIsRegisteredAndReplacesImage(): void
    {
        $registry = ColumnTypeRegistry::withBuiltins();

        $this->assertTrue($registry->has('media'));
        $this->assertInstanceOf(MediaType::class, $registry->get('media'));
        // ImageType was removed: `image` is no longer a known type (beta, no alias).
        $this->assertFalse($registry->has('image'));
    }

    public function testMediaInfersNoFilterAndMediaControl(): void
    {
        $media = new MediaType();

        $this->assertNull($media->inferFilterType());
        $this->assertSame('media', $media->inferControlType());
    }

    public function testMediaRendersImageInlineByExtension(): void
    {
        $type = new MediaType();
        $out  = $this->pipeline($type, ['x' => '/uploads/asset/photo.png']);

        $this->assertInstanceOf(Markup::class, $out);
        $this->assertStringContainsString('<img', (string) $out);
        $this->assertStringContainsString('gv-img', (string) $out);
        $this->assertStringContainsString('/uploads/asset/photo.png', (string) $out);
    }

    public function testMediaRendersDownloadLinkForNonImage(): void
    {
        $type = new MediaType();
        $out  = (string) $this->pipeline($type, ['x' => '/uploads/asset/manual.pdf']);

        $this->assertStringContainsString('<a', $out);
        $this->assertStringContainsString('gv-file', $out);
        $this->assertStringContainsString('download', $out);
        $this->assertStringContainsString('manual.pdf', $out);
    }

    public function testMediaDisplayOptionForcesMode(): void
    {
        $type = new MediaType();

        // Force image even without a telling extension.
        $asImage = (string) $this->pipeline($type, ['x' => '/files/123'], ['display' => 'image']);
        $this->assertStringContainsString('<img', $asImage);

        // Force download even for an image extension.
        $asLink = (string) $this->pipeline($type, ['x' => '/uploads/photo.png'], ['display' => 'download']);
        $this->assertStringContainsString('<a', $asLink);
        $this->assertStringContainsString('gv-file', $asLink);
    }

    public function testMediaMimeOptionDrivesAutoHeuristic(): void
    {
        $type = new MediaType();
        $out  = (string) $this->pipeline($type, ['x' => '/files/123'], ['mimeType' => 'image/png']);

        $this->assertStringContainsString('<img', $out);
    }

    public function testMediaEmptyValueRendersEmpty(): void
    {
        $type = new MediaType();

        $this->assertSame('', $this->pipeline($type, ['x' => null]));
        $this->assertSame('', $this->pipeline($type, ['x' => '']));
    }

    public function testDotPathRawValue(): void
    {
        $type = new TextType();
        $column = $this->column('profile.fullname');
        $raw = $type->getRawValue(['profile' => ['fullname' => 'Jane']], $column);

        $this->assertSame('Jane', $raw);
    }

    public function testCustomTypeOverridesBuiltinByName(): void
    {
        $custom = new class extends MoneyType {
            public function getName(): string
            {
                return 'money';
            }

            public function getDefaultOptions(): array
            {
                return ['currency' => 'USD'] + parent::getDefaultOptions();
            }
        };

        $registry = ColumnTypeRegistry::create([$custom]);

        $this->assertSame($custom, $registry->get('money'));
        $this->assertSame('USD', $registry->get('money')->getDefaultOptions()['currency']);
    }

    public function testTimeInheritsDateFilterAndOwnControl(): void
    {
        $type = new TimeType();

        $this->assertSame('date', $type->inferFilterType());
        $this->assertSame('time', $type->inferControlType());
    }

    public function testTimeFollowsAmbientDefaultLocaleWhenUnset(): void
    {
        $type = new TimeType();

        \Locale::setDefault('it_IT');
        $this->assertSame('15:30', $this->pipeline($type, ['x' => '2026-07-11 15:30:00'], []));
    }

    public function testTimeNoIntlFallbackUsesFixedPattern(): void
    {
        $type = new class extends TimeType {
            protected function intlAvailable(): bool
            {
                return false;
            }
        };

        $this->assertSame('15:30', $this->pipeline($type, ['x' => '2026-07-11 15:30:00'], []));
    }

    public function testColorRendersSwatchForValidHex(): void
    {
        $type = new ColorType();
        $out  = (string) $this->pipeline($type, ['x' => '#ff0000'], []);

        $this->assertStringContainsString('gv-color-swatch', $out);
        $this->assertStringContainsString('background-color:#ff0000', $out);
    }

    public function testColorFallsBackToPlainTextForUntrustedValue(): void
    {
        $type = new ColorType();
        // Not a hex code: never interpolated into a style attribute.
        $out  = (string) $this->pipeline($type, ['x' => 'red;--x:url(javascript:alert(1))'], []);

        $this->assertSame('red;--x:url(javascript:alert(1))', $out);
    }

    public function testColorInfersOwnControl(): void
    {
        $this->assertSame('color', (new ColorType())->inferControlType());
    }

    public function testCountryRendersFlagAndLocalizedNameWithIntl(): void
    {
        $type = new CountryType();

        \Locale::setDefault('en_US');
        $out = (string) $this->pipeline($type, ['x' => 'it'], []);

        $this->assertStringContainsString('Italy', $out);
        $this->assertStringContainsString("\u{1F1EE}\u{1F1F9}", $out); // regional-indicator flag
    }

    public function testCountryRendersBareCodeWithFlagWithoutIntl(): void
    {
        $type = new class extends CountryType {
            protected function intlAvailable(): bool
            {
                return false;
            }
        };

        $out = (string) $this->pipeline($type, ['x' => 'IT'], []);

        $this->assertSame("\u{1F1EE}\u{1F1F9} IT", $out);
    }

    public function testCountryShowFlagFalseOmitsFlag(): void
    {
        $type = new CountryType();
        $out  = (string) $this->pipeline($type, ['x' => 'IT'], ['showFlag' => false, 'showName' => false]);

        $this->assertSame('IT', $out);
    }

    public function testCountryInfersOwnControl(): void
    {
        $this->assertSame('country', (new CountryType())->inferControlType());
    }

    public function testLanguageRendersLocalizedNameWithIntl(): void
    {
        $type = new LanguageType();

        \Locale::setDefault('en_US');
        $out = (string) $this->pipeline($type, ['x' => 'it'], []);

        $this->assertSame('Italian', $out);
    }

    public function testLanguageRendersBareCodeWithoutIntl(): void
    {
        $type = new class extends LanguageType {
            protected function intlAvailable(): bool
            {
                return false;
            }
        };

        $out = (string) $this->pipeline($type, ['x' => 'it'], []);

        $this->assertSame('it', $out);
    }

    public function testLanguageInfersOwnControl(): void
    {
        $this->assertSame('language', (new LanguageType())->inferControlType());
    }

    public function testLocaleRendersLocalizedNameWithIntl(): void
    {
        $type = new LocaleType();

        \Locale::setDefault('en_US');
        $out = (string) $this->pipeline($type, ['x' => 'it_IT'], []);

        $this->assertSame('Italian (Italy)', $out);
    }

    public function testLocaleInfersOwnControl(): void
    {
        $this->assertSame('locale', (new LocaleType())->inferControlType());
    }

    public function testTimezoneRendersIdWithUtcOffset(): void
    {
        $type = new TimezoneType();
        $out  = (string) $this->pipeline($type, ['x' => 'UTC'], []);

        $this->assertSame('UTC (UTC+00:00)', $out);
    }

    public function testTimezoneShowOffsetFalseRendersBareId(): void
    {
        $type = new TimezoneType();
        $out  = (string) $this->pipeline($type, ['x' => 'Europe/Rome'], ['showOffset' => false]);

        $this->assertSame('Europe/Rome', $out);
    }

    public function testTimezoneInfersOwnControl(): void
    {
        $this->assertSame('timezone', (new TimezoneType())->inferControlType());
    }

    public function testHiddenIsPassthroughTextWithNoFilterAndOwnControl(): void
    {
        $type = new HiddenType();
        $out  = $this->pipeline($type, ['x' => 'secret']);

        $this->assertSame('secret', $out);
        $this->assertNull($type->inferFilterType());
        $this->assertSame('hidden', $type->inferControlType());
    }

    public function testTelRendersTelLinkAndInfersOwnControl(): void
    {
        $type = new TelType();
        $out  = (string) $this->pipeline($type, ['x' => '+39 02 1234567'], []);

        // Whitespace is stripped from the tel: URI but kept in the visible label.
        $this->assertStringContainsString('href="tel:+39021234567"', $out);
        $this->assertStringContainsString('>+39 02 1234567<', $out);
        $this->assertSame('tel', $type->inferControlType());
    }
}
