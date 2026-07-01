<?php

namespace Fedale\GridviewBundle\Tests\Filter;

use Fedale\GridviewBundle\Filter\FilterClearNormalizer;
use PHPUnit\Framework\TestCase;

class FilterClearNormalizerTest extends TestCase
{
    public function testDefaultIsHeaderOnly(): void
    {
        $this->assertSame(
            ['mode' => ['header'], 'icon' => null, 'chipIcon' => null],
            FilterClearNormalizer::normalize(null)
        );
    }

    public function testDefaultAddsInputWhenInlineClearEnabled(): void
    {
        $this->assertSame(
            ['mode' => ['header', 'input'], 'icon' => null, 'chipIcon' => null],
            FilterClearNormalizer::normalize(null, true)
        );
    }

    public function testExplicitSpecIgnoresInlineClearDefault(): void
    {
        // An explicit spec wins: no implicit 'input' is appended.
        $this->assertSame(
            ['mode' => ['chip'], 'icon' => null, 'chipIcon' => null],
            FilterClearNormalizer::normalize('chip', true)
        );
    }

    public function testStringShorthand(): void
    {
        $this->assertSame(['header'], FilterClearNormalizer::normalize('header')['mode']);
    }

    public function testModeList(): void
    {
        $this->assertSame(
            ['header', 'chip'],
            FilterClearNormalizer::normalize(['header', 'chip'])['mode']
        );
    }

    public function testExtendedFormWithIcons(): void
    {
        $result = FilterClearNormalizer::normalize([
            'mode'     => ['header', 'chip'],
            'icon'     => '<svg>a</svg>',
            'chipIcon' => '<svg>b</svg>',
        ]);

        $this->assertSame(['header', 'chip'], $result['mode']);
        $this->assertSame('<svg>a</svg>', $result['icon']);
        $this->assertSame('<svg>b</svg>', $result['chipIcon']);
    }

    public function testNoneEmptiesModes(): void
    {
        $this->assertSame([], FilterClearNormalizer::normalize('none')['mode']);
        $this->assertSame([], FilterClearNormalizer::normalize(['header', 'none'])['mode']);
    }

    public function testDeduplicatesModes(): void
    {
        $this->assertSame(['header'], FilterClearNormalizer::normalize(['header', 'header'])['mode']);
    }

    public function testRejectsUnknownMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FilterClearNormalizer::normalize('bogus');
    }

    public function testNullDefaultBehaviorMatches(): void
    {
        // When explicitly passing null + inlineClearDefault=true, the behavior
        // must match the old "inlineClear" gate (for retro-compat).
        $this->assertSame(
            ['header', 'input'],
            FilterClearNormalizer::normalize(null, true)['mode']
        );
    }
}
