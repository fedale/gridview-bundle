<?php 

namespace Fedale\GridviewBundle;

use Fedale\GridviewBundle\Column\Type\ColumnTypeInterface;
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
        $containerConfigurator->import('../config/services.xml');
        $containerConfigurator->import('../config/columns.xml');

        // Any service implementing ExporterInterface (incl. host-app ones) is
        // auto-tagged and collected by the export registry.
        $containerBuilder->registerForAutoconfiguration(ExporterInterface::class)
            ->addTag('fedale_gridview.exporter');

        // Likewise, any host-app ColumnTypeInterface service is auto-tagged and
        // collected by the column type registry (custom data types, zero config).
        $containerBuilder->registerForAutoconfiguration(ColumnTypeInterface::class)
            ->addTag('fedale_gridview.column_type');

        $containerConfigurator->parameters()
            ->set('fedale_gridview.config', $config);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('template')->defaultValue('fedale')->end()
                ->arrayNode('defaults')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('options')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('caption')->defaultNull()->end()
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
                                        ->booleanNode('pageSelect')->defaultTrue()->end()
                                        ->integerNode('pageSelectThreshold')->defaultValue(10)->end()
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
                                        ->scalarNode('gridview')->defaultNull()->end()
                                        ->scalarNode('header')->defaultNull()->end()
                                        ->scalarNode('toolbar')->defaultNull()->end()
                                        ->scalarNode('table')->defaultNull()->end()
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
                                            ->booleanNode('pageSelect')->end()
                                            ->integerNode('pageSelectThreshold')->end()
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
                                            ->scalarNode('gridview')->end()
                                            ->scalarNode('header')->end()
                                            ->scalarNode('toolbar')->end()
                                            ->scalarNode('table')->end()
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