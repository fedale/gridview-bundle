<?php

namespace Fedale\GridviewBundle\Grid;

class GridviewConfigRegistry
{
    /**
     * Single source of truth for the layout token tree. Referenced by
     * {@see Gridview::$options} too, so the in-class and config-resolved defaults
     * never drift. The tree is implicit: each region name is a key whose value is
     * the child-token string; the engine recurses by looking up `layout[child]`.
     *
     * `shell` is the root region (the `_grid` template renders it). `header`
     * wraps `toolbar`; the widgets live in `toolbar` so a controller (e.g. CRUD)
     * overrides one focused key. `dataview` is the renderer-agnostic data region
     * (null → the table strategy default in {@see Gridview::resolveLayout()}).
     * `templates`/`slots`/`attrs` are reserved sub-maps, not regions.
     */
    public const LAYOUT_DEFAULTS = [
        'shell'     => '{header} {dataview} {footer}',
        'header'    => '{heading} {toolbar}',
        'toolbar'   => '{globalSearch} {filterSubmit}',
        'dataview'  => null,
        'footer'    => '{pagination} {pageSize}',
        'tfoot'     => '',
        'templates' => [],
        'slots'     => [],
        'attrs'     => [],
    ];

    private const OPTION_DEFAULTS = [
        'caption'      => null,
        'title'        => null,
        'theme'        => 'default',
        // Data region renderer strategy: picks sections/dataview/{renderer}.html.twig.
        // Only 'table' ships today; 'card'/'list' are planned.
        'renderer'     => 'table',
        'emptyText'    => 'No records found',
        'showThead'    => true,
        'showTfoot'    => true,
        'useTurbo'     => true,
        'globalSearch' => [],
        'addRoute'     => null,
        'addLabel'     => 'Add',
        'formName'     => 'fedaleForm',
        'pagination'   => [
            'pageSelect'          => true,
            'pageSelectThreshold' => 10,
        ],
        'realtime'     => [
            'enabled'     => false,
            'topicPrefix' => 'gridview/',
        ],
        'layout'       => self::LAYOUT_DEFAULTS,
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

        // Global framework theme (top-level `fedale_gridview.theme`); a
        // per-gridview `options.theme` below overrides it.
        if (isset($this->config['theme'])) {
            $resolved['theme'] = $this->config['theme'];
        }

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
                if ($key === 'templates' || $key === 'slots' || $key === 'attrs') {
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
