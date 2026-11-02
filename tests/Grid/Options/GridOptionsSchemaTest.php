<?php

namespace Fedale\GridviewBundle\Tests\Grid\Options;

use Fedale\GridviewBundle\FedaleGridviewBundle;
use Fedale\GridviewBundle\Grid\Options\GridOptionsSchema;
use Fedale\GridviewBundle\Grid\Options\SchemaNode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\Config\FileLocator;

class GridOptionsSchemaTest extends TestCase
{
    /**
     * The drift guard: every option a host may set bundle-wide in YAML
     * (`defaults.display|behavior|integration`) must be a key the runtime
     * schema accepts, or strict mode would reject a grid whose YAML is valid.
     */
    public function testEveryYamlDefaultsKeyIsInTheRuntimeSchema(): void
    {
        $defaults = $this->yamlTree()->getChildren()['defaults'];
        \assert($defaults instanceof ArrayNode);

        $missing = [];
        foreach (['display', 'behavior', 'integration'] as $group) {
            $this->collectMissing($defaults->getChildren()[$group], GridOptionsSchema::get()->children[$group], $group, $missing);
        }

        $this->assertSame([], $missing, 'YAML option keys the runtime schema does not know.');
    }

    public function testThePerGridYamlOverridesAreValidRuntimeOptions(): void
    {
        $perGrid = $this->yamlTree()->getChildren()['gridviews'];
        \assert($perGrid instanceof PrototypedArrayNode);
        $prototype = $perGrid->getPrototype();
        \assert($prototype instanceof ArrayNode);

        $missing = [];
        foreach (['display', 'behavior', 'integration'] as $group) {
            $this->collectMissing($prototype->getChildren()[$group], GridOptionsSchema::get()->children[$group], $group, $missing);
        }

        $this->assertSame([], $missing);
    }

    public function testJsonSchemaExportIsClosedAndTyped(): void
    {
        $schema = GridOptionsSchema::get()->toJsonSchema();
        $json = json_decode((string) json_encode($schema), true);

        $this->assertSame('object', $json['type']);
        $this->assertFalse($json['additionalProperties']);
        $this->assertSame(['display', 'behavior', 'integration', 'actionLayout'], array_keys($json['properties']));
        $this->assertSame(['string', 'null'], $json['properties']['display']['properties']['title']['type']);
        $this->assertSame(['modal', 'page', 'custom'], $json['properties']['behavior']['properties']['crudMode']['enum']);
        // Open regions: any other layout key is a region holding a token string.
        $this->assertSame(['string', 'null'], $json['properties']['display']['properties']['layout']['additionalProperties']['type']);
        // An open bag is written as `true` (anything), never as an empty list.
        $this->assertTrue($json['properties']['integration']['properties']['crud']['additionalProperties']);
    }

    public function testAnUnconstrainedNodeExportsAsAnEmptyObject(): void
    {
        $this->assertSame('{}', json_encode(SchemaNode::any()->toJsonSchema()));
        $this->assertSame('{"type":"array","items":{}}', json_encode(SchemaNode::listOf(SchemaNode::any())->toJsonSchema()));
    }

    public function testNullableEnumListsNull(): void
    {
        $schema = SchemaNode::enum(['a', 'b'])->nullable()->toJsonSchema();

        $this->assertSame(['type' => ['string', 'null'], 'enum' => ['a', 'b', null]], $schema);
    }

    private function yamlTree(): ArrayNode
    {
        $treeBuilder = new TreeBuilder('fedale_gridview');
        $loader = new DefinitionFileLoader($treeBuilder, new FileLocator());
        (new FedaleGridviewBundle())->configure(new DefinitionConfigurator($treeBuilder, $loader, __FILE__, __FILE__));

        $tree = $treeBuilder->buildTree();
        \assert($tree instanceof ArrayNode);

        return $tree;
    }

    /**
     * @param list<string> $missing
     */
    private function collectMissing(NodeInterface $yaml, SchemaNode $runtime, string $path, array &$missing): void
    {
        if (!$yaml instanceof ArrayNode || $yaml instanceof PrototypedArrayNode) {
            return;
        }

        // A union (e.g. the renderer shorthand) is checked against its struct variant.
        if ($runtime->type === SchemaNode::ANY_OF) {
            foreach ($runtime->variants as $variant) {
                if ($variant->type === SchemaNode::STRUCT) {
                    $runtime = $variant;
                    break;
                }
            }
        }
        if ($runtime->type !== SchemaNode::STRUCT) {
            return;
        }

        foreach ($yaml->getChildren() as $name => $child) {
            $childRuntime = $runtime->children[$name] ?? $runtime->additional;
            if ($childRuntime === null) {
                $missing[] = $path . '.' . $name;
                continue;
            }
            $this->collectMissing($child, $childRuntime, $path . '.' . $name, $missing);
        }
    }
}
