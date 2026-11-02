<?php

namespace Fedale\GridviewBundle\Grid\Options;

use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;

/**
 * The shape of a grid's runtime options — the array a controller returns under
 * viewConfig()['options'] and a host passes to GridviewBuilder::setOptions().
 *
 * It is a superset of the YAML `defaults` tree (FedaleGridviewBundle::configure()):
 * besides the keys a host may set bundle-wide, it carries the per-grid keys the
 * controllers wire at runtime (integration.routeName, integration.crud,
 * integration.export, behavior.realtime.topic …). The YAML tree stays the source
 * of the defaults; this schema only says which keys exist and what they hold.
 * GridOptionsSchemaTest keeps the two in step: every key the YAML tree accepts
 * must be accepted here.
 *
 * Values that depend on what is registered (theme names, paginator modes,
 * renderers, layout tokens) are checked by {@see GridOptionsValidator}, not
 * here, so the schema stays static.
 */
final class GridOptionsSchema
{
    public const CRUD_MODES = ['modal', 'page', 'custom'];

    private static ?SchemaNode $schema = null;

    public static function get(): SchemaNode
    {
        return self::$schema ??= self::build();
    }

    private static function build(): SchemaNode
    {
        $nullableString = SchemaNode::string()->nullable();

        $regionKeys = array_values(array_diff(
            array_keys(GridviewConfigRegistry::LAYOUT_DEFAULTS),
            ['templates', 'slots', 'attrs'],
        ));

        $layout = SchemaNode::struct(
            [
                'templates' => SchemaNode::mapOf(SchemaNode::string())
                    ->describe('Template override per token or region (token => Twig template name).'),
                'slots' => SchemaNode::mapOf(SchemaNode::string())
                    ->describe('Inline Twig snippets, each usable as a {token} in any region.'),
                'attrs' => SchemaNode::mapOf(SchemaNode::mapOf(SchemaNode::any()))
                    ->describe('HTML attributes per region (region => attribute map).'),
            ],
            // Every other key is a region: its value is the token string of its
            // children. Declaring a new key creates a new region.
            SchemaNode::string()->nullable(),
        )->describe(sprintf(
            'Token-based layout. Built-in regions: %s. Any other key declares a region whose value is a token string such as "{globalSearch} {spacer} {export}".',
            implode(', ', $regionKeys),
        ));

        $renderer = SchemaNode::anyOf(
            SchemaNode::string()->describe('Shorthand for renderer.default.'),
            SchemaNode::struct([
                'default' => $nullableString->describe('The active renderer (table, list, card, …).'),
                'map'     => SchemaNode::mapOf(SchemaNode::any())
                    ->describe('Renderers offered by {viewSwitcher}, keyed by name; each value is that renderer\'s option bag.'),
            ]),
        );

        $display = SchemaNode::struct([
            'caption'       => $nullableString,
            'title'         => $nullableString->describe('Heading rendered by the {heading} token.'),
            'renderer'      => $renderer,
            'theme'         => SchemaNode::string()->describe('Framework theme for this grid (default, bootstrap5, tailwind, or a custom one).'),
            'emptyText'     => $nullableString,
            'dataErrorText' => $nullableString,
            'showThead'     => SchemaNode::bool(),
            'showTfoot'     => SchemaNode::bool(),
            'addLabel'      => $nullableString,
            'crudTemplate'  => $nullableString->describe('Full-page CRUD wrapper template.'),
            'layout'        => $layout,
        ]);

        $behavior = SchemaNode::struct([
            'useTurbo'       => SchemaNode::bool(),
            'globalSearch'   => SchemaNode::listOf(SchemaNode::string())
                ->describe('Fields searched by the {globalSearch} box, as query paths (e.g. "c.name").'),
            'formName'       => SchemaNode::string(),
            'maxQueryLength' => SchemaNode::int(),
            'crudMode'       => SchemaNode::enum(self::CRUD_MODES),
            'formTheme'      => SchemaNode::anyOf(
                SchemaNode::string(),
                SchemaNode::listOf(SchemaNode::string()),
                SchemaNode::bool()->describe('false opts out to the application\'s global form themes.'),
            )->nullable(),
            'filterControls' => SchemaNode::struct([
                'inHeader'                => SchemaNode::bool(),
                'inlineClear'             => SchemaNode::bool(),
                // Same format as a column's filter.clear: string, list or extended form.
                'clear'                   => SchemaNode::any(),
                'autoBar'                 => SchemaNode::bool()->nullable(),
                'choiceControlsThreshold' => SchemaNode::int(),
            ]),
            'pagination' => SchemaNode::struct([
                'mode'                => SchemaNode::string()->describe('Paginator strategy (numeric, infinite, or a registered one).'),
                'pageSelect'          => SchemaNode::bool(),
                'pageSelectThreshold' => SchemaNode::int(),
                'options'             => SchemaNode::any()->describe('Strategy-specific extras.'),
            ]),
            'realtime' => SchemaNode::struct([
                'enabled'     => SchemaNode::bool(),
                'topicPrefix' => SchemaNode::string(),
                // Resolved by AbstractGridController, not meant to be set by hand.
                'topic'       => $nullableString,
                'hubUrl'      => $nullableString,
            ]),
            'reorderColumns' => SchemaNode::bool(),
            'responsive'     => SchemaNode::bool(),
            'restriction'    => SchemaNode::anyOf(SchemaNode::bool(), SchemaNode::string())
                ->describe('Shows the {restrictionNotice} banner; a string replaces the default message.'),
            // GroupingConfig::fromArray() validates its own keys.
            'grouping'       => SchemaNode::mapOf(SchemaNode::any())->nullable(),
        ]);

        $integration = SchemaNode::struct([
            'routeName'  => $nullableString->describe('Full route name of the list action.'),
            'routeNames' => SchemaNode::mapOf(SchemaNode::string())->describe('Route-name suffix per action (index, create, …).'),
            'addRoute'   => $nullableString,
            // Wired by the CRUD and grid controllers (URLs, bulk actions, export formats).
            'crud'       => SchemaNode::mapOf(SchemaNode::any()),
            'export'     => SchemaNode::mapOf(SchemaNode::any()),
        ]);

        return SchemaNode::struct([
            'display'      => $display,
            'behavior'     => $behavior,
            'integration'  => $integration,
            // Read by AbstractCrudGridController (the action column), never by
            // the grid itself, so it is the one key outside the three groups.
            'actionLayout' => $nullableString->describe('CRUD controllers: token layout of the action column, e.g. "{edit} {clone} {delete}".'),
        ]);
    }
}
