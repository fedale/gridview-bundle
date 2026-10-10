<?php

namespace Fedale\GridviewBundle\Tests\UiSettings;

use Fedale\GridviewBundle\UiSettings\GridDescriptor;
use Fedale\GridviewBundle\UiSettings\RendererUiSetting;
use Fedale\GridviewBundle\UiSettings\UiSettingsResolver;
use Fedale\SettingBundle\Contract\TenantProviderInterface;
use Fedale\SettingBundle\Exception\SettingValidationException;
use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;
use Fedale\SettingBundle\Scoped\ScopedSettingsResolver;
use Fedale\SettingBundle\Scoped\ScopedSettingsWriter;
use Fedale\SettingBundle\Scoped\SettingDefinitionRegistry;
use Fedale\SettingBundle\Scoped\Store\NullScopedStore;
use Fedale\SettingBundle\Scoped\Store\ScopedStoreInterface;
use PHPUnit\Framework\TestCase;

class UiSettingsResolverTest extends TestCase
{
    private const OPTIONS = [
        'display' => [
            'renderer' => ['default' => 'table', 'map' => ['table' => [], 'card' => ['min' => '18rem'], 'list' => []]],
            'emptyText' => 'Nothing',
        ],
    ];

    public function testWithoutStoredValuesTheOptionsAreUntouched(): void
    {
        $resolver = $this->resolver([]);

        self::assertSame(self::OPTIONS, $resolver->apply('post', self::OPTIONS));
    }

    public function testTheGlobalValueOverridesTheControllerDefault(): void
    {
        $resolver = $this->resolver([0 => ['global' => ['renderer' => 'card']]]);

        $options = $resolver->apply('post', self::OPTIONS);

        self::assertSame('card', $options['display']['renderer']['default']);
        // Only the leaf is written: the map and sibling keys survive.
        self::assertSame(self::OPTIONS['display']['renderer']['map'], $options['display']['renderer']['map']);
        self::assertSame('Nothing', $options['display']['emptyText']);
    }

    public function testTheGridValueWinsOverTheGlobalOne(): void
    {
        $resolver = $this->resolver([0 => ['global' => ['renderer' => 'card'], 'grid:post' => ['renderer' => 'list']]]);

        self::assertSame('list', $resolver->apply('post', self::OPTIONS)['display']['renderer']['default']);
        self::assertSame('card', $resolver->apply('category', self::OPTIONS)['display']['renderer']['default']);
    }

    public function testAValueTheGridDoesNotMapIsSkipped(): void
    {
        $tableOnly = ['display' => ['renderer' => ['default' => 'table', 'map' => ['table' => []]]]];
        $resolver = $this->resolver([0 => ['global' => ['renderer' => 'card']]]);

        self::assertSame('table', $resolver->apply('comment', $tableOnly)['display']['renderer']['default']);
    }

    public function testAnInapplicableGridValueFallsBackToTheGlobalOne(): void
    {
        $options = ['display' => ['renderer' => ['default' => 'table', 'map' => ['table' => [], 'card' => []]]]];
        $resolver = $this->resolver([0 => ['global' => ['renderer' => 'card'], 'grid:post' => ['renderer' => 'list']]]);

        self::assertSame('card', $resolver->apply('post', $options)['display']['renderer']['default']);
    }

    public function testTheTenantsGlobalValueBeatsThePlatformsGridValue(): void
    {
        // Tenant 0 only holds platform defaults: a tenant's own choice wins.
        $bags = [
            0 => ['grid:post' => ['renderer' => 'list']],
            5 => ['global' => ['renderer' => 'card']],
        ];

        self::assertSame('card', $this->resolver($bags, 5)->apply('post', self::OPTIONS)['display']['renderer']['default']);
        self::assertSame('list', $this->resolver($bags, 7)->apply('post', self::OPTIONS)['display']['renderer']['default']);
    }

    public function testTheNullStoreDisablesTheFeature(): void
    {
        $resolver = $this->resolver([], 0, new NullScopedStore());

        self::assertFalse($resolver->isEnabled());
        self::assertSame(self::OPTIONS, $resolver->apply('post', self::OPTIONS));
    }

    public function testSaveClearsInheritedAndDropsUnknownKeys(): void
    {
        $store = new InMemoryScopedStore([0 => ['grid:post' => ['renderer' => 'card']]]);
        $resolver = $this->resolver([], 0, $store);
        $post = new GridDescriptor('post', null, self::OPTIONS);

        $resolver->save('post', ['renderer' => null, 'bogus' => 'x'], $post);
        self::assertSame([], $resolver->values('post'));

        $resolver->save('post', ['renderer' => 'list'], $post);
        self::assertSame(['renderer' => 'list'], $resolver->values('post'));
        self::assertSame(['renderer' => 'list'], $store->bags[0]['grid:post']);
    }

    public function testSaveRejectsAGlobalValueOutsideTheChoices(): void
    {
        $this->expectException(SettingValidationException::class);

        $this->resolver([])->save(UiSettingsResolver::GLOBAL_SCOPE, ['renderer' => 'kanban']);
    }

    public function testAGridScopeAcceptsTheGridsOwnRenderers(): void
    {
        // A host renderer is not among the built-in global choices, but a grid
        // that maps it may select it.
        $options = ['display' => ['renderer' => ['default' => 'table', 'map' => ['table' => [], 'kanban' => []]]]];
        $resolver = $this->resolver([]);

        $resolver->save('board', ['renderer' => 'kanban'], new GridDescriptor('board', null, $options));

        self::assertSame('kanban', $resolver->apply('board', $options)['display']['renderer']['default']);
    }

    public function testInheritedNamesWhatAnEmptyFieldFallsBackTo(): void
    {
        $bags = [
            0 => ['global' => ['renderer' => 'list']],
            5 => ['global' => ['renderer' => 'card'], 'grid:post' => ['renderer' => 'table']],
        ];
        $resolver = $this->resolver($bags, 5);
        $post = new GridDescriptor('post', null, self::OPTIONS);
        $tableOnly = new GridDescriptor('comment', null, ['display' => ['renderer' => ['map' => ['table' => []]]]]);

        self::assertSame(['renderer' => 'card'], $resolver->inherited('post', $post));
        self::assertSame(['renderer' => 'list'], $resolver->inherited(UiSettingsResolver::GLOBAL_SCOPE));
        // Neither inherited value fits a table-only grid.
        self::assertSame([], $resolver->inherited('comment', $tableOnly));
    }

    public function testRendererIsOfferedOnlyToGridsWithSeveralViews(): void
    {
        $setting = new RendererUiSetting();
        $multi = new GridDescriptor('post', null, self::OPTIONS);
        $single = new GridDescriptor('comment', null, ['display' => ['renderer' => ['map' => ['table' => []]]]]);
        $none = new GridDescriptor('user', null, []);

        self::assertTrue($setting->supportsGrid($multi));
        self::assertFalse($setting->supportsGrid($single));
        self::assertFalse($setting->supportsGrid($none));
        self::assertSame(
            ['ui_settings.renderer.table' => 'table', 'ui_settings.renderer.card' => 'card', 'ui_settings.renderer.list' => 'list'],
            $setting->choicesFor(null),
        );
        self::assertSame(['table', 'card', 'list'], array_values($setting->choicesFor($multi)));
    }

    public function testRendererScopesAndChoicesForSettingBundle(): void
    {
        $setting = new RendererUiSetting();

        self::assertSame('gridview.ui', $setting->namespace());
        self::assertTrue($setting->supportsScope(Scope::global()));
        self::assertTrue($setting->supportsScope(new Scope('grid', 'post')));
        self::assertFalse($setting->supportsScope(new Scope('calendar', 'main')));
        self::assertSame($setting->choicesFor(null), $setting->choices(Scope::global()));
        self::assertNull($setting->choices(new Scope('grid', 'post')));
    }

    public function testSettingsForFiltersByScope(): void
    {
        $resolver = $this->resolver([]);

        self::assertArrayHasKey('renderer', $resolver->settingsFor(null));
        self::assertArrayHasKey('renderer', $resolver->settingsFor(new GridDescriptor('post', null, self::OPTIONS)));
        self::assertSame([], $resolver->settingsFor(new GridDescriptor('user', null, [])));
    }

    /**
     * @param array<int, array<string, array<string, string>>> $bags tenant => scope => key => stored value
     */
    private function resolver(array $bags, int $tenantId = 0, ?ScopedStoreInterface $store = null): UiSettingsResolver
    {
        $store ??= new InMemoryScopedStore($bags);
        $definitions = new SettingDefinitionRegistry([new RendererUiSetting()]);
        $tenant = new class($tenantId) implements TenantProviderInterface {
            public function __construct(private readonly int $tenantId)
            {
            }

            public function getCurrentTenantId(): int
            {
                return $this->tenantId;
            }
        };
        $core = new ScopedSettingsResolver($definitions, $store, $tenant);

        return new UiSettingsResolver($core, new ScopedSettingsWriter($definitions, $store, $tenant, $core), $definitions);
    }
}

/**
 * Scoped store of a single namespace, in memory.
 */
final class InMemoryScopedStore implements ScopedStoreInterface
{
    /** @param array<int, array<string, array<string, string>>> $bags tenant => scope => key => stored value */
    public function __construct(public array $bags)
    {
    }

    public function load(string $namespace, ScopeChain $chain, array $tenantIds): array
    {
        $result = [];
        foreach ($tenantIds as $tenantId) {
            foreach ($chain->scopes as $scope) {
                $result[$tenantId][(string) $scope] = $this->bags[$tenantId][(string) $scope] ?? [];
            }
        }

        return $result;
    }

    public function save(string $namespace, Scope $scope, int $tenantId, array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($this->bags[$tenantId][(string) $scope][$key]);
            } else {
                $this->bags[$tenantId][(string) $scope][$key] = $value;
            }
        }
    }
}
