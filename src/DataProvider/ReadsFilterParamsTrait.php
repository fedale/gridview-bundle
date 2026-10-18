<?php

namespace Fedale\GridviewBundle\DataProvider;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the filter-form params from the request, and the rule that decides
 * when the column filter defaults apply instead. Shared by the providers that
 * filter from the grid's own form, so both read the same params the same way.
 */
trait ReadsFilterParamsTrait
{
    /** The filter-form param name (grid `formName` option); drives which query params are read. */
    private string $formName = 'fedaleForm';

    /** @var array<string, mixed> */
    private array $params = [];

    /** The request being served, read afresh on every access (see docs/14_extending.md, "Request-scoped services"). */
    abstract protected function currentRequest(): ?Request;

    private function populateParams(): void
    {
        $this->params = $this->currentRequest()?->query->all($this->formName) ?? [];
    }

    /**
     * Set the filter-form param name (the grid `formName` option) and re-read the
     * request params under it. The grid forwards the configured name before
     * prepareModels().
     */
    public function setFormName(string $formName): void
    {
        $this->formName = $formName;
        $this->populateParams();
    }

    /**
     * @param array<string, mixed> $defaults
     */
    public function setDefaultParams(array $defaults): void
    {
        parent::setDefaultParams($defaults);

        if ($defaults === []) {
            return;
        }

        // A submitted GET form always sends every field (even empty), so a
        // present-but-empty form param means the user cleared the filters:
        // defaults apply only when the form is absent from the query string.
        $request = $this->currentRequest();
        if ($request !== null && $request->query->has($this->formName)) {
            return;
        }

        $this->params = $defaults;
    }

    /**
     * The raw filter-form params read from the request (for the profiler).
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
