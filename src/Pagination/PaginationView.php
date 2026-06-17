<?php

namespace Fedale\GridviewBundle\Pagination;

use Fedale\GridviewBundle\Contract\PaginationInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RequestStack;

// Prefer this solution!
// https://gist.github.com/Naskalin/6306172b8081813ea213099a4d16019a

class PaginationView
{
    /**
     * @var Pagination
     */
    protected Pagination $pagination;

    /**
     * @var RouterInterface
     */
    protected RouterInterface $router;

    /**
     * @var ParameterBag|array
     */
    protected ParameterBag|array $queryParameters;

    /**
     * @var array Parameter list for the pagination block.
     */
    protected array $options = ['class' => 'pagination'];

    /**
     * @var array Parameter list for each pagination link.
     */
    protected array $linkOptions = ['class' => 'page-link'];

    protected array $buttonOptions = ['class' => 'page-item'];

    /**
     * @var string CSS class name for first page button.
     */
    public string $firstPageCssClass = 'first';

    /**
     * @var string CSS class name for last page button.
     */
    public string $lastPageCssClass = 'last';

    /**
     * @var string CSS class name for previous page button.
     */
    public string $prevPageCssClass = 'prev';

    /**
     * @var string CSS class name for next page button.
     */
    public string $nextPageCssClass = 'next';

    /**
     * @var string CSS class name for active page button
     */
    public string $activePageCssClass = 'active';

    /**
     * @var string CSS class for disabled page button.
     */
    public string $disabledPageCssClass = 'disabled';

    /**
     * @var int Max number of buttons to show.
     */
    public int $maxButtonCount = 10;

    /**
     * @var bool Whether to show a link to the first page.
     */
    public bool $showFirstPageLink = false;

    /**
     * @var bool Whether to show a link to the last page
     */
    public bool $showLastPageLink = false;

    /**
     * @var bool Whether to show a link to the previous page.
     */
    public bool $showPrevPageLink = true;

    /**
     * @var bool Whether to show a link to the next page
     */
    public bool $showNextPageLink = true;

    /**
     * @var string Next page default label. Will be used if [[showNextPageLink]]
     * set to true.
     */
    public string $nextPageLabel = '&rsaquo;';

    /**
     * @var string Precious page default label. Will be used if
     * [[showPrevPageLink]] set to true.
     */
    public string $prevPageLabel = '&lsaquo;';

    /**
     * @var string First page default label. Will be used if
     * [[showFirstPageLink]] set to true.
     */
    public string $firstPageLabel = '&laquo;';

    /**
     * @var string Last page default label. Will be used if
     * [[showLastPageLink]] set to true.
     */
    public string $lastPageLabel = '&raquo;';

    /**
     * PaginationView constructor.
     *
     * @param RequestStack $request
     * @param RouterInterface $router
     */
    public function __construct(RequestStack $request, RouterInterface $router)
    {
        $currentRequest = $request->getCurrentRequest();

        if ($currentRequest) {
            $this->queryParameters = $currentRequest->query->all();
        }

        $this->router = $router;
    }

    /**
     * Render pagination block.
     *
     * @return string
     * @throws \Exception
     */
    public function renderPageButtons(): string
    {
        if (!$this->pagination) {
            throw new \Exception(
                'Instance of ' . Pagination::class . ' should be specified.'
            );
        }

        $pageCount = $this->pagination->getPageCount();

        if ($pageCount < 2) {
            return '';
        }

        $buttons = [];

        $currentPage = $this->pagination->getCurrentPage();

        if ($this->showFirstPageLink) {
            $buttons[] = $this->createPageButton(
                $this->firstPageLabel,
                0,
                $this->firstPageCssClass,
                $currentPage <= 0,
                false
            );
        }

        if ($this->showPrevPageLink) {
            $buttons[] = $this->createPageButton(
                $this->prevPageLabel,
                max(0, $currentPage - 1),
                $this->prevPageCssClass,
                $currentPage <= 0,
                false
            );
        }

        [$startPage, $endPage] = $this->getPageRange();

        for ($i = $startPage; $i <= $endPage; ++$i) {
            $buttons[] = $this->createPageButton(
                $i + 1,
                $i,
                null,
                false,
                $i == $currentPage
            );
        }

        if ($this->showNextPageLink) {
            $buttons[] = $this->createPageButton(
                $this->nextPageLabel,
                min($currentPage + 1, $pageCount - 1),
                $this->nextPageCssClass,
                $currentPage >= $pageCount - 1,
                false
            );
        }

        if ($this->showLastPageLink) {
            $buttons[] = $this->createPageButton(
                $this->lastPageLabel,
                $pageCount - 1,
                $this->lastPageCssClass,
                $currentPage >= $pageCount - 1,
                false
            );
        }

        return '<ul>'
            . implode("\n", $buttons)
            . '</ul>';
    }

    /**
     * Render single pagination block button.
     *
     * @param string $label
     * @param int $page
     * @param string|null $class
     * @param bool $disabled
     * @param bool $active
     *
     * @return string
     */
    protected function createPageButton(string $label, int $page, ?string $class, bool $disabled, bool $active): string
    {
        $buttonClassList = [$class];

        if ($active) {
            array_push($buttonClassList, $this->activePageCssClass);
        }

        if ($disabled) {
            array_push($buttonClassList, $this->disabledPageCssClass);
        }

        $buttonOptions = array_merge_recursive($this->buttonOptions, ['class' => $buttonClassList]);

        $linkOptions = $this->linkOptions;

        if ($active) {
            $linkOptions = array_merge(
                $linkOptions,
                ['data-page' => $page]
            );
        }

        $link = '<a href="'
            .$this->createButtonLink($page, $this->pagination->getPageSize())
            .'">';
        $link .= $label;
        $link .= '</a>';

        return '<li>' . $link . '</li>';
    }

    /**
     * Create link for pagination button.
     *
     * @param int $pageIndex
     * @param int $pageSize
     * @param bool $absoluteUrl
     *
     * @return string
     */
    public function createButtonLink(int $pageIndex, int $pageSize, bool $absoluteUrl = true): string
    {
        $pageParamName = $this->pagination->getPageParamName();
        $pageSizeParamName = $this->pagination->getPageSizeParam();

        if ($pageIndex > 0) {
            $this->queryParameters[$pageParamName] = $pageIndex + 1;
        } else {
            unset($this->queryParameters[$pageParamName]);
        }

        if ($pageSize != $this->pagination->getDefaultPageSize()) {
            $this->queryParameters[$pageSizeParamName] = $pageSize;
        } else {
            unset($this->queryParameters[$pageSizeParamName]);
        }

        return $this->router->generate(
            $this->pagination->getRoute(),
            $this->queryParameters,
            $absoluteUrl ? RouterInterface::ABSOLUTE_URL : RouterInterface::ABSOLUTE_PATH
        );
    }

    /**
     * Calculate current pagination pages range.
     *
     * @return array
     */
    protected function getPageRange(): array
    {
        $pageCount = $this->pagination->getPageCount();

        $beginPage = max(
            0,
            $this->pagination->getCurrentPage() - floor(
                $this->maxButtonCount / 2
            )
        );

        $endPage = $beginPage + $this->maxButtonCount - 1;

        if ($endPage >= $pageCount) {
            $endPage = $pageCount - 1;

            $beginPage = max(0, $endPage - $this->maxButtonCount + 1);
        }

        return [$beginPage, $endPage];
    }

    /**
     * @param PaginationInterface $pagination
     *
     * @return $this
     */
    public function setPagination(PaginationInterface $pagination): static
    {
        $this->pagination = $pagination;

        return $this;
    }

    /**
     * Set pagination block options.
     *
     * @param array $options
     *
     * @return $this
     */
    public function setOptions(array $options): static
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    /**
     * Set parameters for each pagination link.
     *
     * @param array $linkOptions
     *
     * @return $this
     */
    public function setLinkOptions(array $linkOptions): static
    {
        $this->linkOptions = array_merge($this->linkOptions, $linkOptions);
        return $this;
    }
}
