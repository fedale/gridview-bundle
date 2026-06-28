<?php

namespace Fedale\GridviewBundle\Tests\Theme;

use Fedale\GridviewBundle\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;

class ThemeRegistryTest extends TestCase
{
    public function testDefaultThemeReturnsGvClasses(): void
    {
        $registry = new ThemeRegistry();

        $this->assertSame('gv-btn gv-btn-primary', $registry->resolve('default', 'btn.primary'));
        $this->assertSame('gv-btn gv-btn-danger', $registry->resolve('default', 'btn.danger'));
        $this->assertSame('gv-btn-icon', $registry->resolve('default', 'btn.icon'));
    }

    public function testBootstrap5ThemeEmitsRealBootstrapClasses(): void
    {
        $registry = new ThemeRegistry();

        $this->assertSame('btn btn-sm btn-primary', $registry->resolve('bootstrap5', 'btn.primary'));
        $this->assertSame('btn btn-sm btn-danger', $registry->resolve('bootstrap5', 'btn.danger'));
    }

    public function testTailwindThemeEmitsUtilityClasses(): void
    {
        $registry = new ThemeRegistry();

        $this->assertStringContainsString('bg-indigo-600', $registry->resolve('tailwind', 'btn.primary'));
    }

    public function testOmittedKeyFallsBackToDefault(): void
    {
        // bootstrap5 has no specific icon class → inherits the default gv-btn-icon.
        $registry = new ThemeRegistry();

        $this->assertSame('gv-btn-icon', $registry->resolve('bootstrap5', 'btn.icon'));
    }

    public function testUnknownThemeFallsBackToDefault(): void
    {
        $registry = new ThemeRegistry();

        $this->assertSame('gv-btn gv-btn-primary', $registry->resolve('does-not-exist', 'btn.primary'));
    }

    public function testCustomThemeFromYamlOverridesAndInheritsDefault(): void
    {
        $registry = new ThemeRegistry([
            'mycss' => ['classes' => ['btn.primary' => 'c-button c-button--primary']],
        ]);

        // Overridden key.
        $this->assertSame('c-button c-button--primary', $registry->resolve('mycss', 'btn.primary'));
        // Omitted key → default fallback (partial custom theme is valid).
        $this->assertSame('gv-btn-icon', $registry->resolve('mycss', 'btn.icon'));
    }

    public function testCustomThemeExtendsBuiltin(): void
    {
        $registry = new ThemeRegistry([
            'bs-tweak' => [
                'extends' => 'bootstrap5',
                'classes' => ['btn.primary' => 'btn btn-lg btn-primary'],
            ],
        ]);

        // Own override.
        $this->assertSame('btn btn-lg btn-primary', $registry->resolve('bs-tweak', 'btn.primary'));
        // Inherited from the extended bootstrap5 theme.
        $this->assertSame('btn btn-sm btn-danger', $registry->resolve('bs-tweak', 'btn.danger'));
    }

    public function testCustomThemeWinsOverSameNamedBuiltin(): void
    {
        $registry = new ThemeRegistry([
            'bootstrap5' => ['classes' => ['btn.primary' => 'my-override']],
        ]);

        $this->assertSame('my-override', $registry->resolve('bootstrap5', 'btn.primary'));
    }

    public function testExtendsCycleIsGuarded(): void
    {
        $registry = new ThemeRegistry([
            'loop' => ['extends' => 'loop', 'classes' => ['btn' => 'x']],
        ]);

        // Must not recurse infinitely; the self-extends is ignored.
        $this->assertSame('x', $registry->resolve('loop', 'btn'));
        $this->assertSame('gv-btn gv-btn-primary', $registry->resolve('loop', 'btn.primary'));
    }

    public function testPaginationKeysAreThemed(): void
    {
        $registry = new ThemeRegistry();

        $this->assertSame('gv-pagination', $registry->resolve('default', 'pagination'));
        $this->assertSame('gv-page-item', $registry->resolve('default', 'pagination.item'));
        $this->assertSame('gv-active', $registry->resolve('default', 'pagination.active'));

        $this->assertSame('pagination', $registry->resolve('bootstrap5', 'pagination'));
        $this->assertSame('page-link', $registry->resolve('bootstrap5', 'pagination.link'));
        $this->assertSame('active', $registry->resolve('bootstrap5', 'pagination.active'));
        $this->assertSame('disabled', $registry->resolve('bootstrap5', 'pagination.disabled'));
    }

    public function testTailwindFallsBackToGvPagination(): void
    {
        // Tailwind defines no pagination component → structural gv-* fallback.
        $registry = new ThemeRegistry();

        $this->assertSame('gv-pagination', $registry->resolve('tailwind', 'pagination'));
        $this->assertSame('gv-page-item', $registry->resolve('tailwind', 'pagination.item'));
    }

    public function testClassMapIsComplete(): void
    {
        $registry = new ThemeRegistry();

        $keys = array_keys($registry->classMap('bootstrap5'));
        sort($keys);

        $this->assertSame([
            'btn', 'btn.danger', 'btn.icon', 'btn.primary',
            'pagination', 'pagination.active', 'pagination.disabled',
            'pagination.item', 'pagination.link',
        ], $keys);
    }

    public function testKnownThemesListsBuiltinAndCustom(): void
    {
        $registry = new ThemeRegistry(['mycss' => ['classes' => []]]);

        $known = $registry->knownThemes();

        $this->assertContains('default', $known);
        $this->assertContains('bootstrap5', $known);
        $this->assertContains('tailwind', $known);
        $this->assertContains('mycss', $known);
    }
}
