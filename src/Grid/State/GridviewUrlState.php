<?php

namespace Fedale\GridviewBundle\Grid\State;

use Symfony\Component\HttpFoundation\Request;

class GridviewUrlState
{
    private array   $filters      = [];
    private ?string $globalSearch = null;
    private ?string $sort         = null;
    private int     $page         = 1;

    private string $formName  = 'myform';
    private string $sortParam = 'sort';
    private string $pageParam = 'page';

    public static function fromRequest(
        Request $request,
        string $formName  = 'myform',
        string $sortParam = 'sort',
        string $pageParam = 'page'
    ): static {
        $state = new static();
        $state->formName  = $formName;
        $state->sortParam = $sortParam;
        $state->pageParam = $pageParam;

        $formData = $request->query->all($formName);
        $state->globalSearch = $formData['_q'] ?? null;
        unset($formData['_q'], $formData['save'], $formData['_token']);
        $state->filters = array_filter($formData, fn($v) => $v !== null && $v !== '');

        $state->sort = $request->query->get($sortParam) ?: null;
        $state->page = max(1, (int) $request->query->get($pageParam, 1));

        return $state;
    }

    /** Tutti i parametri correnti come array (per Symfony path()) */
    public function toArray(): array
    {
        $params = [];

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

    public function getFilters(): array       { return $this->filters; }
    public function getSort(): ?string        { return $this->sort; }
    public function getPage(): int            { return $this->page; }
    public function getGlobalSearch(): ?string { return $this->globalSearch; }
}
