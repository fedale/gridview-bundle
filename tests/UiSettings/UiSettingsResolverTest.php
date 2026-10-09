<?php

namespace Fedale\GridviewBundle\Tests\UiSettings;

use Fedale\GridviewBundle\UiSettings\GridDescriptor;
use Fedale\GridviewBundle\UiSettings\NullUiSettingsStore;
use Fedale\GridviewBundle\UiSettings\RendererUiSetting;
use Fedale\GridviewBundle\UiSettings\UiSettingsResolver;
use Fedale\GridviewBundle\UiSettings\UiSettingsStoreInterface;
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
        $resolver = $this->resolver(['_global' => ['renderer' => 'card']]);

        $options = $resolver->apply('post', self::OPTIONS);

        self::assertSame('card', $options['display']['renderer']['default']);
        // Only the leaf is written: the map and sibling keys survive.
        self::assertSame(self::OPTIONS['display']['renderer']['map'], $options['display']['renderer']['map']);
        self::assertSame('Nothing', $options['display']['emptyText']);
    }

    public function testTheGridValueWinsOverTheGlobalOne(): void
    {
        $resolver = $this->resolver(['_global' => ['renderer' => 'card'], 'post' => ['renderer' => 'list']]);

        self::assertSame('list', $resolver->apply('post', self::OPTIONS)['display']['renderer']['default']);
        self::assertSame('card', $resolver->apply('category', self::OPTIONS)['display']['renderer']['default']);
    }

    public function testAValueTheGridDoesNotMapIsSkipped(): void
    {
        $tableOnly = ['display' => ['renderer' => ['default' => 'table', 'map' => ['table' => []]]]];
        $resolver = $this->resolver(['_global' => ['renderer' => 'card']]);

        self::assertSame('table', $resolver->apply('comment', $tableOnly)['display']['renderer']['default']);
    }

    public function testAnInapplicableGridValueFallsBackToTheGlobalOne(): void
    {
        $options = ['display' => ['renderer' => ['default' => 'table', 'map' => ['table' => [], 'card' => []]]]];
        $resolver = $this->resolver(['_global' => ['renderer' => 'card'], 'post' => ['renderer' => 'list']]);

        self::assertSame('card', $resolver->apply('post', $options)['display']['renderer']['default']);
    }

    public function testTheNullStoreDisablesTheFeature(): void
    {
        $resolver = new UiSettingsResolver([new RendererUiSetting()], new NullUiSettingsStore());

        self::assertFalse($resolver->isEnabled());
        self::assertSame(self::OPTIONS, $resolver->apply('post', self::OPTIONS));
    }

    public function testSaveDropsInheritedAndUnknownKeys(): void
    {
        $store = new InMemoryUiSettingsStore([]);
        $resolver = new UiSettingsResolver([new RendererUiSetting()], $store);

        $resolver->save('post', ['renderer' => null, 'bogus' => 'x']);
        self::assertSame([], $store->load('post'));

        $resolver->save('post', ['renderer' => 'list']);
        self::assertSame(['renderer' => 'list'], $store->load('post'));
        self::assertSame(['renderer' => 'list'], $resolver->values('post'));
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
            $setting->choices(null),
        );
        self::assertSame(['table', 'card', 'list'], array_values($setting->choices($multi)));
    }

    public function testSettingsForFiltersByScope(): void
    {
        $resolver = $this->resolver([]);

        self::assertArrayHasKey('renderer', $resolver->settingsFor(null));
        self::assertArrayHasKey('renderer', $resolver->settingsFor(new GridDescriptor('post', null, self::OPTIONS)));
        self::assertSame([], $resolver->settingsFor(new GridDescriptor('user', null, [])));
    }

    /** @param array<string, array<string, mixed>> $bags */
    private function resolver(array $bags): UiSettingsResolver
    {
        return new UiSettingsResolver([new RendererUiSetting()], new InMemoryUiSettingsStore($bags));
    }
}

final class InMemoryUiSettingsStore implements UiSettingsStoreInterface
{
    /** @param array<string, array<string, mixed>> $bags */
    public function __construct(private array $bags)
    {
    }

    public function load(string $scope): array
    {
        return $this->bags[$scope] ?? [];
    }

    public function save(string $scope, array $values): void
    {
        $this->bags[$scope] = $values;
    }
}
