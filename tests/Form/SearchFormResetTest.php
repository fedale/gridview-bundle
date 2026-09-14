<?php

namespace Fedale\GridviewBundle\Tests\Form;

use Fedale\GridviewBundle\Filter\Applier\FilterApplierRegistry;
use Fedale\GridviewBundle\Form\SearchForm;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Regression cover for the grid under a worker runtime.
 *
 * A Symfony Form is single-use: once handleRequest() has submitted it, binding
 * it to a second request is a no-op. Under FrankenPHP worker mode the container
 * outlives the request, so a SearchForm that built its form once served every
 * later request with the first one's filter data — and did it quietly, because
 * the result count is computed from the raw request params and stayed right.
 *
 * Each test here drives two requests through one instance, which is what a
 * worker does and what a fresh-container test never exercises.
 */
class SearchFormResetTest extends TestCase
{
    private function createSearchForm(): SearchForm
    {
        return new SearchForm(
            // With the HttpFoundation extension, so handleRequest() accepts a
            // Request object as it does in the application. The bare factory
            // installs the native handler, which reads $_GET and rejects one.
            Forms::createFormFactoryBuilder()
                ->addExtension(new HttpFoundationExtension())
                ->getFormFactory(),
            new RequestStack(),
            new FilterApplierRegistry(),
        );
    }

    private static function request(string $name): Request
    {
        return Request::create('/grid?' . http_build_query([
            'fedaleForm' => ['name' => $name],
        ]));
    }

    /**
     * The bug itself: the second request's filter value must reach the form.
     */
    public function testSecondRequestIsBoundAfterReset(): void
    {
        $searchForm = $this->createSearchForm();

        $searchForm->addFilter('name', 'text', []);
        $searchForm->getModelType()->handleRequest(self::request('acme'));

        $this->assertSame('acme', $searchForm->getModelType()->get('name')->getData());

        // What the worker does between two requests.
        $searchForm->reset();

        // The columns are rebuilt per request, so the field is registered again.
        $searchForm->addFilter('name', 'text', []);
        $searchForm->getModelType()->handleRequest(self::request('globex'));

        $this->assertSame(
            'globex',
            $searchForm->getModelType()->get('name')->getData(),
            'The form kept the previous request\'s data, so the grid would render rows for a filter nobody submitted.',
        );
    }

    /**
     * Without reset() the stale form is not merely wrong, it is unbindable:
     * this is the state the fix exists to avoid, pinned so the single-use
     * nature of Form stays visible to anyone reading these tests.
     */
    public function testFormStaysSubmittedUntilReset(): void
    {
        $searchForm = $this->createSearchForm();

        $searchForm->addFilter('name', 'text', []);
        $searchForm->getModelType()->handleRequest(self::request('acme'));

        $this->assertTrue($searchForm->getModelType()->isSubmitted());

        $searchForm->reset();

        $this->assertFalse(
            $searchForm->getModelType()->isSubmitted(),
            'reset() must hand back a form that can still be bound to a request.',
        );
    }

    /**
     * reset() discards the registered appliers along with the form. Callers
     * register them per grid as closures capturing that request's services, so
     * holding them would pin request-scoped objects for the worker's lifetime.
     */
    public function testResetDropsRegisteredAppliers(): void
    {
        $searchForm = $this->createSearchForm();

        $registry = $searchForm->getApplierRegistry();

        $searchForm->reset();

        $this->assertNotSame(
            $registry,
            $searchForm->getApplierRegistry(),
            'A reset instance must hand out a fresh applier registry.',
        );
    }

    /**
     * The form is built on demand, so an instance that is never used for a
     * search costs nothing — and reset() on an untouched instance is safe.
     */
    public function testResetIsSafeBeforeAnyUse(): void
    {
        $searchForm = $this->createSearchForm();

        $searchForm->reset();

        $this->assertTrue($searchForm->getModelType()->has('save'));
        $this->assertFalse($searchForm->getModelType()->isSubmitted());
    }
}
