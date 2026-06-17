<?php
namespace Fedale\GridviewBundle\Grid;

class GridviewConfigRegistry
{
    private const OPTION_DEFAULTS = [
        'caption'      => null,
        'emptyText'    => 'No records found',
        'showThead'    => true,
        'showTfoot'    => true,
        'useTurbo'     => true,
        'globalSearch' => [],
        'addRoute'     => null,
        'addLabel'     => 'Add',
        'formName'     => 'myform',
        'pagination'   => [
            'pageSelect'          => true,
            'pageSelectThreshold' => 10,
        ],
        'realtime'     => [
            'enabled'     => false,
            'topicPrefix' => 'gridview/',
        ],
        'layout'       => [
            'gridview'  => '{header} {table} {footer}',
            'header'    => '{globalSearch} {filterSubmit}',
            'toolbar'   => '',
            'table'     => null,
            'footer'    => '{pagination}',
            'tfoot'     => '',
            'templates' => [],
            'slots'     => [],
        ],
    ];

    /**
     * Detail-view defaults. Deliberately disjoint from OPTION_DEFAULTS: a detail
     * has no pagination/realtime/global-search nor a table layout — only the few
     * knobs that make sense for a key/value record view.
     */
    private const DETAIL_OPTION_DEFAULTS = [
        'emptyText'   => 'No data',
        'onlyVisible' => false,
        'template'    => '@FedaleGridview/detailview/detailview.html.twig',
    ];

    private const DETAIL_ATTRIBUTE_DEFAULTS = [
        'class' => 'table table-bordered',
    ];

    public function __construct(private array $config) {}

    public function resolveOptions(?string $id): array
    {
        $yamlDefaults = $this->config['defaults']['options'] ?? [];

        $resolved = array_replace(self::OPTION_DEFAULTS, $yamlDefaults);
        $resolved['layout'] = $this->mergeLayout($yamlDefaults['layout'] ?? []);

        if ($id !== null && isset($this->config['gridviews'][$id]['options'])) {
            $gridviewOptions = $this->config['gridviews'][$id]['options'];
            $resolved = array_replace($resolved, $gridviewOptions);
            $resolved['layout'] = $this->mergeLayout(
                $yamlDefaults['layout'] ?? [],
                $gridviewOptions['layout'] ?? []
            );
        }

        return $resolved;
    }

    private function mergeLayout(array ...$layers): array
    {
        $result = self::OPTION_DEFAULTS['layout'];
        foreach ($layers as $layer) {
            foreach ($layer as $key => $value) {
                if ($key === 'templates' || $key === 'slots') {
                    if (!empty($value)) {
                        $result[$key] = array_replace($result[$key], $value);
                    }
                } elseif ($value !== null) {
                    $result[$key] = $value;
                }
            }
        }
        return $result;
    }

    public function resolveAttributes(?string $id): array
    {
        $resolved = ['row' => [], 'container' => [], 'header' => [], 'filter' => []];

        $resolved = $this->mergeAttributeLayer($resolved, $this->config['defaults']['attributes'] ?? []);

        if ($id !== null && isset($this->config['gridviews'][$id]['attributes'])) {
            $resolved = $this->mergeAttributeLayer($resolved, $this->config['gridviews'][$id]['attributes']);
        }

        return $resolved;
    }

    private function mergeAttributeLayer(array $base, array $layer): array
    {
        if (isset($layer['class']) && $layer['class'] !== null) {
            $base['class'] = $layer['class'];
        }
        foreach (['row', 'container', 'header', 'filter'] as $key) {
            if (!empty($layer[$key])) {
                $base[$key] = array_replace($base[$key] ?? [], $layer[$key]);
            }
        }
        return $base;
    }

    /**
     * Options for a DetailView. Sibling of {@see resolveOptions()} but it reads
     * the dedicated `defaults.detailview` / `detailviews.<id>` sections — never
     * the grid-only `gridviews.<id>`, whose pagination/realtime/layout keys are
     * meaningless for a single record. Per-id overrides win over defaults.
     */
    public function resolveDetailOptions(?string $id): array
    {
        $resolved = array_replace(
            self::DETAIL_OPTION_DEFAULTS,
            $this->config['defaults']['detailview']['options'] ?? []
        );

        if ($id !== null && isset($this->config['detailviews'][$id]['options'])) {
            $resolved = array_replace($resolved, $this->config['detailviews'][$id]['options']);
        }

        return $resolved;
    }

    /**
     * Table-level HTML attributes for a DetailView. The detail bag is flat
     * (class + arbitrary attrs); merging is plain key-by-key (per-id over
     * defaults over the built-in default class).
     */
    public function resolveDetailAttributes(?string $id): array
    {
        $resolved = $this->mergeDetailAttributeLayer(
            self::DETAIL_ATTRIBUTE_DEFAULTS,
            $this->config['defaults']['detailview']['attributes'] ?? []
        );

        if ($id !== null && isset($this->config['detailviews'][$id]['attributes'])) {
            $resolved = $this->mergeDetailAttributeLayer($resolved, $this->config['detailviews'][$id]['attributes']);
        }

        return $resolved;
    }

    private function mergeDetailAttributeLayer(array $base, array $layer): array
    {
        return array_replace($base, $layer);
    }
}
