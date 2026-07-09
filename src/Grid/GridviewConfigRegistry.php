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
        'shell'     => '{restrictionNotice} {header} {dataview} {footer}',
        'header'    => '{heading} {toolbar}',
        'toolbar'   => '{globalSearch} {filterSubmit}',
        'dataview'  => null,
        'footer'    => '{resultsSummary} {pagination} {pageSize}',
        'tfoot'     => '',
        'templates' => [],
        'slots'     => [],
        'attrs'     => [],
    ];

    /**
     * `display` / `behavior` / `integration` are a readability grouping only —
     * see FedaleGridviewBundle::configure() — resolved and returned as a single
     * nested array by {@see resolveOptions()}.
     */
    private const DISPLAY_DEFAULTS = [
        'caption'    => null,
        'title'      => null,
        // Data-renderer configuration. `default` picks the active strategy
        // (sections/dataview/{renderer}.html.twig); `map` keys are the available
        // renderers (their values are per-renderer option bags). The runtime
        // {viewSwitcher} offers the map keys; empty/single = single-view grid.
        // Built-in: 'table' (default), 'list', 'card'.
        'renderer'   => [
            'default' => 'table',
            'map'     => [],
        ],
        'emptyText'  => 'No records found',
        'showThead'  => true,
        'showTfoot'  => true,
        'addLabel'   => 'Add',
        // Full-page CRUD wrapper template; bridged from viewConfig()['template']['page'].
        'crudTemplate' => null,
        'layout'     => self::LAYOUT_DEFAULTS,
    ];

    private const BEHAVIOR_DEFAULTS = [
        'useTurbo'       => true,
        'globalSearch'   => [],
        'formName'       => 'fedaleForm',
        'maxQueryLength' => 4000,
        // 'modal' | 'page' | 'custom'; bridged from viewConfig()['form']['mode'].
        'crudMode'       => 'modal',
        'filterControls' => [
            'inHeader'                => true,
            'inlineClear'             => false,
            'clear'                   => null,
            'autoBar'                 => null,
            'choiceControlsThreshold' => 20,
        ],
        'pagination'     => [
            'pageSelect'          => true,
            'pageSelectThreshold' => 10,
        ],
        'realtime'       => [
            'enabled'     => false,
            'topicPrefix' => 'gridview/',
        ],
        'reorderColumns' => false,
        'responsive'     => false,
        // When truthy, the {restrictionNotice} section renders a banner telling
        // the user the list is filtered by their permissions (see the docs). A
        // string value overrides the default translated message.
        'restriction'    => false,
    ];

    private const INTEGRATION_DEFAULTS = [
        'addRoute' => null,
    ];

    /**
     * Detail-view defaults. Deliberately disjoint from the grid defaults above: a detail
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
        $displayDefaults = $this->config['defaults']['display'] ?? [];

        $display     = array_replace(self::DISPLAY_DEFAULTS, $displayDefaults);
        $behavior    = array_replace(self::BEHAVIOR_DEFAULTS, $this->config['defaults']['behavior'] ?? []);
        $integration = array_replace(self::INTEGRATION_DEFAULTS, $this->config['defaults']['integration'] ?? []);
        $display['layout'] = $this->mergeLayout($displayDefaults['layout'] ?? []);

        // Global framework theme (top-level `fedale_gridview.theme`); a
        // per-gridview `display.theme` below overrides it.
        if (isset($this->config['theme'])) {
            $display['theme'] = $this->config['theme'];
        }

        if ($id !== null) {
            $gridview = $this->config['gridviews'][$id] ?? [];

            if (isset($gridview['display'])) {
                $display = array_replace($display, $gridview['display']);
                $display['layout'] = $this->mergeLayout(
                    $displayDefaults['layout'] ?? [],
                    $gridview['display']['layout'] ?? []
                );
            }
            if (isset($gridview['behavior'])) {
                $behavior = array_replace($behavior, $gridview['behavior']);
            }
            if (isset($gridview['integration'])) {
                $integration = array_replace($integration, $gridview['integration']);
            }
        }

        return ['display' => $display, 'behavior' => $behavior, 'integration' => $integration];
    }

    private function mergeLayout(array ...$layers): array
    {
        $result = self::DISPLAY_DEFAULTS['layout'];
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
