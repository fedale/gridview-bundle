<?php

namespace Fedale\GridviewBundle\Capabilities;

use Fedale\GridviewBundle\Column\Type\ColumnTypeRegistry;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\Export\GridExporterRegistry;
use Fedale\GridviewBundle\Filter\FilterType;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use Fedale\GridviewBundle\Grid\Options\GridOptionsSchema;
use Fedale\GridviewBundle\Grid\TemplateCatalog;
use Fedale\GridviewBundle\Pagination\Strategy\PaginatorStrategyRegistry;
use Fedale\GridviewBundle\Theme\ThemeRegistry;

/**
 * What a grid can be configured with in this application, as plain data: the
 * registered column, filter and control types, the export formats, themes,
 * paginator modes, renderers and layout tokens, plus the JSON Schema of the
 * grid options.
 *
 * Meant for tools that write grid configuration rather than humans reading
 * docs: an app builder, an MCP server, an editor plugin. Every list is read
 * from the registries at call time, so host-registered types and formats are
 * included and nothing here can drift from what the grid actually accepts.
 * Pair it with {@see \Fedale\GridviewBundle\Grid\Options\GridOptionsValidator}
 * to check what such a tool produces.
 */
class GridCapabilities
{
    public function __construct(
        private readonly ColumnTypeRegistry $columnTypes,
        private readonly ControlTypeRegistry $controlTypes,
        private readonly GridExporterRegistry $exporters,
        private readonly ThemeRegistry $themes,
        private readonly PaginatorStrategyRegistry $paginators,
        private readonly TemplateCatalog $templates,
    ) {
    }

    /**
     * @return array{
     *     columnTypes: list<string>,
     *     filterTypes: list<string>,
     *     filterOperators: list<string>,
     *     controlTypes: list<string>,
     *     exportFormats: list<array{key: string, label: string}>,
     *     themes: list<string>,
     *     paginationModes: list<string>,
     *     crudModes: list<string>,
     *     renderers: list<string>,
     *     layout: array{regions: array<string, string|null>, blocks: list<string>, tableTokens: list<string>},
     *     optionsSchema: array<string, mixed>,
     * }
     */
    public function describe(): array
    {
        $regions = array_diff_key(
            GridviewConfigRegistry::LAYOUT_DEFAULTS,
            array_flip(['templates', 'slots', 'attrs']),
        );

        $schema = GridOptionsSchema::get()->toJsonSchema();

        return [
            'columnTypes'     => $this->sorted($this->columnTypes->names()),
            'filterTypes'     => array_column(FilterType::cases(), 'value'),
            'filterOperators' => array_column(FilterOperator::cases(), 'value'),
            'controlTypes'    => $this->sorted($this->controlTypes->names()),
            'exportFormats'   => array_values(array_map(
                static fn($e) => ['key' => $e->getKey(), 'label' => $e->getLabel()],
                $this->exporters->all(),
            )),
            'themes'          => $this->sorted($this->themes->knownThemes()),
            'paginationModes' => $this->sorted($this->paginators->names()),
            'crudModes'       => GridOptionsSchema::CRUD_MODES,
            'renderers'       => $this->templates->builtinRenderers(),
            'layout'          => [
                // Region => its default children, so a tool sees where each token sits.
                'regions'     => $regions,
                'blocks'      => $this->templates->builtinBlocks(),
                'tableTokens' => $this->templates->tableTokens(),
            ],
            'optionsSchema'   => ['$schema' => 'https://json-schema.org/draft/2020-12/schema'] + (array) $schema,
        ];
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
