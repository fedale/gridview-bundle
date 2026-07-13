<?php

namespace Fedale\GridviewBundle\Tests\Maker;

use Fedale\GridviewBundle\Maker\Util\ViewConfigFooterInjector;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

class ViewConfigFooterInjectorTest extends TestCase
{
    private const LAYOUT = '{resultsSummary} {pagination} {pageSize}';

    private ViewConfigFooterInjector $injector;

    protected function setUp(): void
    {
        $this->injector = new ViewConfigFooterInjector();
    }

    public function testCommentedOutViewConfigIsNotDetected(): void
    {
        self::assertFalse($this->injector->hasViewConfig($this->scaffoldedSource()));
    }

    public function testAddFooterInjectsProtectedMethodWithFooterRegion(): void
    {
        $out = $this->injector->addFooter($this->scaffoldedSource(), self::LAYOUT);

        self::assertStringContainsString('protected function viewConfig(): array', $out);
        self::assertStringContainsString("'footer' => '" . self::LAYOUT . "'", $out);
        self::assertStringContainsString("'options'", $out);
        self::assertStringContainsString("'display'", $out);
        self::assertStringContainsString("'layout'", $out);
    }

    public function testAddFooterProducesParsableSource(): void
    {
        $out = $this->injector->addFooter($this->scaffoldedSource(), self::LAYOUT);
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($out);

        self::assertNotNull($ast);
    }

    public function testInjectedMethodIsThenDetected(): void
    {
        $out = $this->injector->addFooter($this->scaffoldedSource(), self::LAYOUT);

        self::assertTrue($this->injector->hasViewConfig($out));
    }

    public function testActiveViewConfigIsDetected(): void
    {
        self::assertTrue($this->injector->hasViewConfig($this->sourceWithActiveViewConfig()));
    }

    public function testSnippetContainsTheFooterLayout(): void
    {
        $snippet = $this->injector->snippet(self::LAYOUT);

        self::assertStringContainsString("'footer' => '" . self::LAYOUT . "'", $snippet);
    }

    /** Mirrors the maker skeleton: viewConfig() present only as a comment block. */
    private function scaffoldedSource(): string
    {
        return <<<'PHP'
            <?php

            namespace App\Controller\Gridview;

            use App\Entity\Post;
            use Fedale\GridviewBundle\Controller\AbstractCrudGridController;
            use Symfony\Component\Routing\Attribute\Route;

            #[Route('/gridview/posts', name: 'gridview_post_')]
            class PostController extends AbstractCrudGridController
            {
                protected function getDataClass(): string
                {
                    return Post::class;
                }

                // protected function viewConfig(): array
                // {
                //     return [];
                // }

                protected function dataConfig(): array
                {
                    return ['model' => Post::class];
                }

                /** @return array<int, mixed> */
                protected function buildColumns(): array
                {
                    return [];
                }
            }
            PHP;
    }

    private function sourceWithActiveViewConfig(): string
    {
        return <<<'PHP'
            <?php

            namespace App\Controller\Gridview;

            use App\Entity\Post;
            use Fedale\GridviewBundle\Controller\AbstractCrudGridController;

            class PostController extends AbstractCrudGridController
            {
                protected function viewConfig(): array
                {
                    return ['options' => []];
                }
            }
            PHP;
    }
}
