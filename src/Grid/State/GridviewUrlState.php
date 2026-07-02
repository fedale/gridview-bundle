<?php

namespace Fedale\GridviewBundle\Grid\State;

use Symfony\Component\HttpFoundation\Request;

class GridviewUrlState
{
    private array   $filters      = [];
    private ?string $globalSearch = null;
    private ?string $sort         = null;
    private int     $page         = 1;
    private ?int    $pageSize     = null;
    private ?string $view         = null;

    /** Parametri della rotta corrente (es. {id}), necessari per rigenerarne l'URL. */
    private array   $routeParams  = [];

    private string $formName      = 'fedaleForm';
    private string $sortParam     = 'sort';
    private string $pageParam     = 'page';
    private string $pageSizeParam = 'per-page';
    private string $viewParam     = 'view';

    public static function fromRequest(
        Request $request,
        string $formName      = 'fedaleForm',
        string $sortParam     = 'sort',
        string $pageParam     = 'page',
        string $pageSizeParam = 'per-page',
        string $viewParam     = 'view'
    ): static {
        $state = new static();
        $state->formName      = $formName;
        $state->sortParam     = $sortParam;
        $state->pageParam     = $pageParam;
        $state->pageSizeParam = $pageSizeParam;
        $state->viewParam     = $viewParam;

        $formData = $request->query->all($formName);
        $state->globalSearch = $formData['_q'] ?? null;
        unset($formData['_q'], $formData['save'], $formData['_token']);
        $state->filters = array_filter($formData, fn($v) => $v !== null && $v !== '');

        $state->sort = $request->query->get($sortParam) ?: null;
        $state->page = max(1, (int) $request->query->get($pageParam, 1));
        $state->view = $request->query->get($viewParam) ?: null;

        $requestedSize = (int) $request->query->get($pageSizeParam, 0);
        $state->pageSize = $requestedSize > 0 ? $requestedSize : null;

        // Parametri di rotta (es. {id}) necessari a path() per rigenerare l'URL corrente.
        $routeParams = $request->attributes->get('_route_params', []);
        $state->routeParams = is_array($routeParams) ? $routeParams : [];

        return $state;
    }

    /** Tutti i parametri correnti come array (per Symfony path()) */
    public function toArray(): array
    {
        $params = $this->routeParams;

        $form = $this->filters;
        if ($this->globalSearch !== null && $this->globalSearch !== '') {
            $form['_q'] = $this->globalSearch;
        }
        if ($form) {
            $params[$this->formName] = $form;
        }
        if ($this->sort) {
            $params[$this->sortParam] = $this->sort;
        }
        if ($this->page > 1) {
            $params[$this->pageParam] = $this->page;
        }
        // Preserve the chosen page size across sort/page navigation.
        if ($this->pageSize !== null) {
            $params[$this->pageSizeParam] = $this->pageSize;
        }
        // Preserve the active view (renderer) across sort/filter/page navigation.
        if ($this->view !== null && $this->view !== '') {
            $params[$this->viewParam] = $this->view;
        }

        return $params;
    }

    /** Parametri per un link di sort — resetta la pagina */
    public function withSort(string $sortValue): array
    {
        return array_merge($this->toArray(), [
            $this->sortParam => $sortValue,
            $this->pageParam => null,
        ]);
    }

    /** Parametri per un link di pagina — mantiene sort e filtri */
    public function withPage(int $page): array
    {
        return array_merge($this->toArray(), [$this->pageParam => $page]);
    }

    /** Parametri per il selettore di dimensione-pagina — resetta la pagina */
    public function withPageSize(int $size): array
    {
        return array_merge($this->toArray(), [
            $this->pageSizeParam => $size,
            $this->pageParam     => null,
        ]);
    }

    /** Parametri per il cambio vista (renderer) — mantiene sort/filtri, resetta la pagina */
    public function withView(string $view): array
    {
        return array_merge($this->toArray(), [
            $this->viewParam => $view,
            $this->pageParam => null,
        ]);
    }

    public function getFilters(): array
    {
        return $this->filters;
    }
    public function getSort(): ?string
    {
        return $this->sort;
    }
    public function getPage(): int
    {
        return $this->page;
    }
    public function getGlobalSearch(): ?string
    {
        return $this->globalSearch;
    }
    public function getView(): ?string
    {
        return $this->view;
    }
}
