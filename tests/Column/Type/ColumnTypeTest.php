<?php

namespace Fedale\GridviewBundle\Tests\Column\Type;

use Fedale\GridviewBundle\Column\Type\BadgeType;
use Fedale\GridviewBundle\Column\Type\ColumnTypeInterface;
use Fedale\GridviewBundle\Column\Type\ColumnTypeRegistry;
use Fedale\GridviewBundle\Column\Type\CurrencyType;
use Fedale\GridviewBundle\Column\Type\MediaType;
use Fedale\GridviewBundle\Column\Type\NumberType;
use Fedale\GridviewBundle\Column\Type\SelectType;
use Fedale\GridviewBundle\Column\Type\TextType;
use Fedale\GridviewBundle\Contract\ColumnInterface;
use PHPUnit\Framework\TestCase;
use Twig\Markup;

class ColumnTypeTest extends TestCase
{
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
        $this->assertTrue($registry->has('currency'));
        $this->assertTrue($registry->has('rating'));
        // aliases
        $this->assertInstanceOf(SelectType::class, $registry->get('choice'));
        $this->assertInstanceOf(TextType::class, $registry->get('data'));
    }

    public function testInheritanceMirrorsExtends(): void
    {
        $registry = ColumnTypeRegistry::withBuiltins();

        $this->assertInstanceOf(NumberType::class, $registry->get('currency'));
        $this->assertInstanceOf(SelectType::class, $registry->get('rating'));
        $this->assertInstanceOf(SelectType::class, $registry->get('badge'));
        $this->assertSame('number', $registry->get('currency')->getParent());
        $this->assertSame('select', $registry->get('rating')->getParent());
    }

    public function testCurrencyInheritsNumberFilterAndControl(): void
    {
        $currency = new CurrencyType();

        $this->assertSame('number', $currency->inferFilterType());
        $this->assertSame('number', $currency->inferControlType());
    }

    public function testNumberFormatsAndWrapsRightAligned(): void
    {
        $type = new NumberType();
        $out  = $this->pipeline($type, ['x' => 1234.56], ['decimals' => 2]);

        $this->assertInstanceOf(Markup::class, $out);
        $this->assertStringContainsString('gv-num', (string) $out);
        $this->assertStringContainsString('1.234,56', (string) $out);
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
        $custom = new class extends CurrencyType {
            public function getName(): string
            {
                return 'currency';
            }

            public function getDefaultOptions(): array
            {
                return ['currency' => 'USD'] + parent::getDefaultOptions();
            }
        };

        $registry = ColumnTypeRegistry::create([$custom]);

        $this->assertSame($custom, $registry->get('currency'));
        $this->assertSame('USD', $registry->get('currency')->getDefaultOptions()['currency']);
    }
}
