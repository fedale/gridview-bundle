<?php

namespace Fedale\GridviewBundle\Tests\Form;

use Doctrine\ORM\QueryBuilder;
use Fedale\GridviewBundle\Contract\FilterApplierInterface;
use Fedale\GridviewBundle\Filter\Applier\FilterApplierRegistry;
use Fedale\GridviewBundle\Form\SearchForm;
use Fedale\GridviewBundle\Tests\Support\CreatesQueryBuilderTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Per-grid appliers: the supported way to bind a filter type only one grid
 * knows how to resolve.
 *
 * The alternative — a controller writing into the shared FilterApplierRegistry
 * — publishes those appliers to every other grid in the process and, under a
 * worker runtime, outlives the request that built them. These live on the
 * search form, which is reset per request.
 */
class SearchFormAppliersTest extends TestCase
{
    use CreatesQueryBuilderTrait;

    private function createSearchForm(): SearchForm
    {
        return new SearchForm(
            Forms::createFormFactory(),
            new RequestStack(),
            new FilterApplierRegistry(),
        );
    }

    /**
     * Records what it was handed, so a test can assert the dispatch rather than
     * the DQL some built-in applier happens to produce.
     */
    private function recordingApplier(): FilterApplierInterface
    {
        return new class implements FilterApplierInterface {
            /** @var list<array{string, mixed}> */
            public array $calls = [];

            public function apply(QueryBuilder $qb, string $dqlField, mixed $rawValue, array $options = []): void
            {
                $this->calls[] = [$dqlField, $rawValue];
            }
        };
    }

    public function testAGridSuppliedApplierResolvesAnOtherwiseUnknownType(): void
    {
        $searchForm = $this->createSearchForm();
        $applier = $this->recordingApplier();

        $searchForm->setAppliers(['facet_tier' => $applier]);

        $searchForm->applyFilters(
            $this->createTestQueryBuilder(),
            ['facet_tier' => ['Tier 1']],
            ['facet_tier' => ['facet_tier', 'c.id']],
        );

        $this->assertSame([['c.id', ['Tier 1']]], $applier->calls);
    }

    /**
     * Without the per-grid applier the same map is an error, which is what makes
     * the test above meaningful: the type is genuinely unknown to the registry.
     */
    public function testTheSameTypeIsUnknownWithoutIt(): void
    {
        $searchForm = $this->createSearchForm();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown filter applier type "facet_tier"');

        $searchForm->applyFilters(
            $this->createTestQueryBuilder(),
            ['facet_tier' => ['Tier 1']],
            ['facet_tier' => ['facet_tier', 'c.id']],
        );
    }

    /**
     * A grid may also claim a built-in type for itself. The registry is left
     * untouched, which is the whole point — a second grid in the same process
     * still gets the built-in.
     */
    public function testAGridSuppliedApplierWinsOverABuiltInType(): void
    {
        $registry = new FilterApplierRegistry();
        $searchForm = new SearchForm(Forms::createFormFactory(), new RequestStack(), $registry);
        $applier = $this->recordingApplier();

        $searchForm->setAppliers(['text' => $applier]);

        $searchForm->applyFilters(
            $this->createTestQueryBuilder(),
            ['code' => 'abc'],
            ['code' => ['text', 'c.code']],
        );

        $this->assertSame([['c.code', 'abc']], $applier->calls);
        $this->assertNotSame(
            $applier,
            $registry->get('text'),
            'the shared registry must not have been mutated',
        );
    }

    /**
     * Types the grid says nothing about still come from the registry.
     */
    public function testUnclaimedTypesStillComeFromTheRegistry(): void
    {
        $searchForm = $this->createSearchForm();
        $qb = $this->createTestQueryBuilder();

        $searchForm->setAppliers(['facet_tier' => $this->recordingApplier()]);

        $searchForm->applyFilters($qb, ['code' => 'abc'], ['code' => ['text', 'c.code']]);

        $params = $qb->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(
            sprintf('LOWER(c.code) LIKE :%s', $params[0]->getName()),
            $this->whereDql($qb),
        );
    }

    /**
     * reset() drops them along with the form: they are request state, and the
     * closures they carry would otherwise be pinned for the worker's lifetime.
     */
    public function testResetDropsThem(): void
    {
        $searchForm = $this->createSearchForm();
        $searchForm->setAppliers(['facet_tier' => $this->recordingApplier()]);

        $searchForm->reset();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown filter applier type "facet_tier"');

        $searchForm->applyFilters(
            $this->createTestQueryBuilder(),
            ['facet_tier' => ['Tier 1']],
            ['facet_tier' => ['facet_tier', 'c.id']],
        );
    }
}
