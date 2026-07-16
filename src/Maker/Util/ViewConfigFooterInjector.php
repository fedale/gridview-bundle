<?php

namespace Fedale\GridviewBundle\Maker\Util;

use PhpParser\Builder;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Bundle\MakerBundle\Util\ClassSourceManipulator;

/**
 * Pure source-to-source transformation used by {@see \Fedale\GridviewBundle\Maker\MakeGridCrud}
 * to set the layout footer region of an already-generated CRUD controller.
 *
 * It adds a `viewConfig()` method carrying `options.display.layout.footer`. The
 * detection is AST-based on purpose: the scaffolded controller ships a
 * commented-out `viewConfig()` block, and a plain text search would match it.
 */
final class ViewConfigFooterInjector
{
    /** Whether the controller source already defines an active (non-commented) `viewConfig()`. */
    public function hasViewConfig(string $source): bool
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        if ($ast === null) {
            return false;
        }

        $found = (new NodeFinder())->findFirst(
            $ast,
            static fn(Node $node): bool => $node instanceof ClassMethod && $node->name->toString() === 'viewConfig',
        );

        return $found !== null;
    }

    /**
     * Return $source with a new protected `viewConfig()` method setting the footer
     * region to $layout. The caller must ensure no active `viewConfig()` exists
     * (see {@see hasViewConfig()}); this never merges into an existing one.
     */
    public function addFooter(string $source, string $layout): string
    {
        $manipulator = new ClassSourceManipulator($source);

        // Built directly (not via createMethodBuilder(), which forces public and
        // would then throw when re-flagged protected) so viewConfig() keeps the
        // protected visibility it overrides.
        $builder = (new Builder\Method('viewConfig'))
            ->makeProtected()
            ->setReturnType('array');

        // ClassSourceManipulator's parser expects a full snippet with the tag.
        $body = "<?php\nreturn " . PhpArrayPrinter::export($this->footerConfig($layout), 0) . ';';
        $manipulator->addMethodBuilder($builder, [], $body);

        return $manipulator->getSourceCode();
    }

    /** Paste-in snippet printed when an active `viewConfig()` already exists. */
    public function snippet(string $layout): string
    {
        return PhpArrayPrinter::export($this->footerConfig($layout), 0);
    }

    /** @return array<string, mixed> */
    private function footerConfig(string $layout): array
    {
        return [
            'options' => [
                'display' => [
                    'layout' => [
                        'footer' => $layout,
                    ],
                ],
            ],
        ];
    }
}
