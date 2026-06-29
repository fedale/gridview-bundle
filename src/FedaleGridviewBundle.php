<?php 

namespace Fedale\GridviewBundle;

use Fedale\GridviewBundle\Column\Type\ColumnTypeInterface;
use Fedale\GridviewBundle\Contract\PaginatorStrategyInterface;
use Fedale\GridviewBundle\Export\ExporterInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Gridview Bundle
 */
class FedaleGridviewBundle extends AbstractBundle
{
    //protected string $extensionAlias = 'gridview'; 

    public function loadExtension(array $config, ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void
    {
        // Service definitions live in YAML: Symfony 8 dropped XML support from the
        // DependencyInjection component (no XmlFileLoader), and the AbstractBundle
        // configurator loader only handles PHP/YAML. YAML works on 6.4 through 8.x.
        $containerConfigurator->import('../config/services.yaml');
        $containerConfigurator->import('../config/columns.yaml');

        // Any service implementing ExporterInterface (incl. host-app ones) is
        // auto-tagged and collected by the export registry.
        $containerBuilder->registerForAutoconfiguration(ExporterInterface::class)
            ->addTag('fedale_gridview.exporter');

        // Likewise, any host-app ColumnTypeInterface service is auto-tagged and
        // collected by the column type registry (custom data types, zero config).
        $containerBuilder->registerForAutoconfiguration(ColumnTypeInterface::class)
            ->addTag('fedale_gridview.column_type');

        // Any PaginatorStrategyInterface service (incl. host-app ones) is auto-tagged
        // and collected by the paginator strategy registry, selectable via
        // `options.pagination.mode`. Reusing a built-in name overrides it.
        $containerBuilder->registerForAutoconfiguration(PaginatorStrategyInterface::class)
            ->addTag('fedale_gridview.paginator_strategy');

        $containerConfigurator->parameters()
            ->set('fedale_gridview.config', $config)
            ->set('fedale_gridview.themes', $config['themes'] ?? []);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('template')->defaultValue('fedale')->end()
                // Global framework theme: maps the grid's presentational class
                // keys to a framework's real classes. 'default' keeps the
                // agnostic gv-* look. Built-ins: bootstrap5, tailwind. Custom
                // themes are declared under `themes` below. Per-gridview override
                // via `gridviews.<id>.options.theme`.
                ->scalarNode('theme')->defaultValue('default')->end()
                // Host-declared custom themes: map class keys (e.g. 'btn.primary')
                // to concrete CSS classes. `extends` starts from a built-in theme
                // and overrides only some keys; omitted keys fall back to default.
                ->arrayNode('themes')
                    ->info('Custom framework themes (class key => CSS classes), declared by the host app.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('extends')->defaultNull()->end()
                            ->arrayNode('classes')
                                ->normalizeKeys(false)
                                ->scalarPrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                // Instant client-side i18n. The full catalog (all locales) is
                // shipped to the browser; a headless JS runtime swaps text with
                // zero server roundtrip. Any external language switcher can drive
                // it (DOM event / global API / <html lang> observation).
                ->arrayNode('i18n')
                    ->addDefaultsIfNotSet()
                    ->info('Instant client-side translation of the grid chrome and column labels.')
                    ->children()
                        // Locales to ship to the browser (full catalog each).
                        ->arrayNode('locales')
                            ->scalarPrototype()->end()
                            ->defaultValue(['en'])
                        ->end()
                        ->scalarNode('default')->defaultValue('en')->end()
                        // Translation domain for app/tenant column labels & captions.
                        ->scalarNode('client_domain')->defaultValue('Gridview')->end()
                        // DOM event an external switcher dispatches to set the locale
                        // (null disables the listener). detail[external_event_key] = locale.
                        ->scalarNode('external_event')->defaultValue('gridview:set-locale')->end()
                        ->scalarNode('external_event_key')->defaultValue('locale')->end()
                        // Mirror the grid locale onto <html lang> changes (zero host code).
                        ->booleanNode('observe_html_lang')->defaultTrue()->end()
                        // Render the bundle's own switcher only when the page has none.
                        ->booleanNode('lang_switcher')->defaultFalse()->end()
                        // Persist (localStorage + cookie) on external-switcher changes too.
                        ->booleanNode('persist_external')->defaultTrue()->end()
                        ->scalarNode('cookie_name')->defaultValue('gv_locale')->end()
                    ->end()
                ->end()
                ->arrayNode('defaults')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('options')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('caption')->defaultNull()->end()
                                ->scalarNode('title')->defaultNull()->end()
                                ->scalarNode('renderer')->defaultValue('table')->end()
                                ->scalarNode('emptyText')->defaultValue('No records found')->end()
                                ->booleanNode('showThead')->defaultTrue()->end()
                                ->booleanNode('showTfoot')->defaultTrue()->end()
                                ->booleanNode('useTurbo')->defaultTrue()->end()
                                ->arrayNode('globalSearch')
                                    ->scalarPrototype()->end()
                                    ->defaultValue([])
                                ->end()
                                ->scalarNode('addRoute')->defaultNull()->end()
                                ->scalarNode('addLabel')->defaultValue('Add')->end()
                                ->scalarNode('formName')->defaultValue('myform')->end()
                                ->integerNode('maxQueryLength')->defaultValue(4000)->end()
                                ->arrayNode('filterControls')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('headerIcon')->defaultTrue()->end()
                                        ->booleanNode('inlineClear')->defaultFalse()->end()
                                    ->end()
                                ->end()
                                ->arrayNode('pagination')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        // Paginator strategy key (registry name). 'numeric' is the
                                        // built-in; host apps can register/override others.
                                        ->scalarNode('mode')->defaultValue('numeric')->end()
                                        ->booleanNode('pageSelect')->defaultTrue()->end()
                                        ->integerNode('pageSelectThreshold')->defaultValue(10)->end()
                                        // Strategy-specific extras (e.g. infiniteRootMargin).
                                        ->variableNode('options')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('realtime')
                                    ->addDefaultsIfNotSet()
                                    ->info('Real-time grid updates over Mercure (signal + auto-refresh).')
                                    ->children()
                                        ->booleanNode('enabled')->defaultFalse()->end()
                                        ->scalarNode('topicPrefix')->defaultValue('gridview/')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('layout')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('shell')->defaultNull()->end()
                                        ->scalarNode('header')->defaultNull()->end()
                                        ->scalarNode('toolbar')->defaultNull()->end()
                                        ->scalarNode('dataview')->defaultNull()->end()
                                        ->scalarNode('footer')->defaultNull()->end()
                                        ->scalarNode('tfoot')->defaultNull()->end()
                                        ->arrayNode('templates')
                                            ->normalizeKeys(false)
                                            ->scalarPrototype()->end()
                                        ->end()
                                        ->arrayNode('slots')
                                            ->normalizeKeys(false)
                                            ->scalarPrototype()->end()
                                        ->end()
                                        // Per-region HTML attributes (region name => attr map).
                                        // Generalizes the fixed attributes.row/container/header/filter
                                        // bag; any region (shell, header, thead, …) can carry attrs.
                                        ->arrayNode('attrs')
                                            ->normalizeKeys(false)
                                            ->arrayPrototype()
                                                ->normalizeKeys(false)
                                                ->variablePrototype()->end()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('attributes')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('class')->defaultNull()->end()
                                ->arrayNode('row')->normalizeKeys(false)->variablePrototype()->end()->end()
                                ->arrayNode('container')->normalizeKeys(false)->variablePrototype()->end()->end()
                                ->arrayNode('header')->normalizeKeys(false)->variablePrototype()->end()->end()
                                ->arrayNode('filter')->normalizeKeys(false)->variablePrototype()->end()->end()
                            ->end()
                        ->end()
                        // Defaults for ALL detail views. Sibling of the grid
                        // options/attributes above; keys are detail-only (no
                        // pagination/realtime/table layout), so permissive
                        // variableNode bags keep it future-proof.
                        ->arrayNode('detailview')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('options')->normalizeKeys(false)->variablePrototype()->end()->end()
                                ->arrayNode('attributes')->normalizeKeys(false)->variablePrototype()->end()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('gridviews')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('options')
                                ->children()
                                    ->scalarNode('caption')->end()
                                    ->scalarNode('title')->end()
                                    ->scalarNode('renderer')->end()
                                    ->scalarNode('theme')->end()
                                    ->scalarNode('emptyText')->end()
                                    ->booleanNode('showThead')->end()
                                    ->booleanNode('showTfoot')->end()
                                    ->booleanNode('useTurbo')->end()
                                    ->arrayNode('globalSearch')
                                        ->scalarPrototype()->end()
                                    ->end()
                                    ->scalarNode('addRoute')->end()
                                    ->scalarNode('addLabel')->end()
                                    ->integerNode('maxQueryLength')->end()
                                    ->arrayNode('filterControls')
                                        ->children()
                                            ->booleanNode('headerIcon')->end()
                                            ->booleanNode('inlineClear')->end()
                                        ->end()
                                    ->end()
                                    ->arrayNode('pagination')
                                        ->children()
                                            ->scalarNode('mode')->end()
                                            ->booleanNode('pageSelect')->end()
                                            ->integerNode('pageSelectThreshold')->end()
                                            ->variableNode('options')->end()
                                        ->end()
                                    ->end()
                                    ->arrayNode('realtime')
                                        ->children()
                                            ->booleanNode('enabled')->end()
                                            ->scalarNode('topicPrefix')->end()
                                        ->end()
                                    ->end()
                                    ->arrayNode('layout')
                                        ->children()
                                            ->scalarNode('shell')->end()
                                            ->scalarNode('header')->end()
                                            ->scalarNode('toolbar')->end()
                                            ->scalarNode('dataview')->end()
                                            ->scalarNode('footer')->end()
                                            ->scalarNode('tfoot')->end()
                                            ->arrayNode('templates')
                                                ->normalizeKeys(false)
                                                ->scalarPrototype()->end()
                                            ->end()
                                            ->arrayNode('slots')
                                                ->normalizeKeys(false)
                                                ->scalarPrototype()->end()
                                            ->end()
                                            ->arrayNode('attrs')
                                                ->normalizeKeys(false)
                                                ->arrayPrototype()
                                                    ->normalizeKeys(false)
                                                    ->variablePrototype()->end()
                                                ->end()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->arrayNode('attributes')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->scalarNode('class')->defaultNull()->end()
                                    ->arrayNode('row')->normalizeKeys(false)->variablePrototype()->end()->end()
                                    ->arrayNode('container')->normalizeKeys(false)->variablePrototype()->end()->end()
                                    ->arrayNode('header')->normalizeKeys(false)->variablePrototype()->end()->end()
                                    ->arrayNode('filter')->normalizeKeys(false)->variablePrototype()->end()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                // Per-id detail-view overrides. Sibling of `gridviews`: grid
                // and detail of the same entity SHARE the id (entity short name,
                // lowercased) but live in separate sections — no semantic clash.
                ->arrayNode('detailviews')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('options')->normalizeKeys(false)->variablePrototype()->end()->end()
                            ->arrayNode('attributes')->normalizeKeys(false)->variablePrototype()->end()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

//     public function build(ContainerBuilder $container): void
//   {
//       $container->addCompilerPass($this);
//       //$container->addCompilerPass(new RegisterServiceSubscribersPass());
//   }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}