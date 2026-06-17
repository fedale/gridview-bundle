<?php

namespace Fedale\GridviewBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Export\GridExporterRegistry;
use Fedale\GridviewBundle\Grid\Gridview;
use Fedale\GridviewBundle\Grid\GridviewBuilderFactory;
use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Read-only grid controller base. Provides the `index` and `export` actions and
 * assembles the {@see Gridview} from the host controller's configuration
 * (entity, columns, data provider). The `#[Route]` attributes live on these
 * methods and are inherited by each concrete controller, picking up that
 * controller's own class-level route prefix; a concrete controller can override
 * any action (redeclaring the method with a new `#[Route]`) for special cases.
 *
 * Services are pulled lazily via {@see getSubscribedServices()} so subclasses
 * never have to redeclare a wide constructor. For full CRUD, extend
 * {@see AbstractCrudGridController} instead.
 */
abstract class AbstractGridController extends AbstractController
{
    private ?string $routePrefix = null;

    private ?array $resolvedConfig = null;

    private ?array $resolvedRealtime = null;

    // ---- required configuration ----------------------------------------

    /** FQCN of the entity backing the grid (e.g. User::class). */
    abstract protected function getDataClass(): string;

    /** @return array<int, mixed> Column definitions, as consumed by the ColumnFactory. */
    abstract protected function buildColumns(): array;

    /** @return array<string, mixed> Data-provider options: models/pagination/sort. */
    abstract protected function getDataProviderConfig(): array;

    // ---- configuration -------------------------------------------------

    /**
     * Per-controller overrides merged over {@see defaultConfig()}. A concrete
     * controller only lists the keys it wants to change.
     *
     * @return array<string, mixed>
     */
    protected function configure(): array
    {
        return [];
    }

    /**
     * Default config. `id` defaults to the entity short name (e.g. User → "user");
     * `exportFilename` falls back to `id` when null.
     *
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        $id = strtolower((new \ReflectionClass($this->getDataClass()))->getShortName());

        return [
            'id'             => $id,                              // grid id + YAML lookup
            'indexTemplate'  => 'gridview/with_sidebar.html.twig',
            'exportFilename' => null,                            // null → fallback su 'id'
            'attributes'     => ['class' => 'table'],            // table-level HTML attrs
            'options'        => [],                              // extra builder opts (layout, ...)
        ];
    }

    /** Resolved config accessor (whole array when $key is null). Resolved once. */
    protected function config(?string $key = null, mixed $default = null): mixed
    {
        if ($this->resolvedConfig === null) {
            $this->resolvedConfig = array_replace($this->defaultConfig(), $this->configure());
        }

        if ($key === null) {
            return $this->resolvedConfig;
        }

        return $this->resolvedConfig[$key] ?? $default;
    }

    /** @return array<string, mixed> CRUD-specific grid options; empty for read-only grids. */
    protected function crudOptions(): array
    {
        return [];
    }

    // ---- actions -------------------------------------------------------

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // When real-time is active, authorize this user to subscribe to *this*
        // grid's private topic only. The cookie is attached to the response by
        // Mercure's SetCookieSubscriber. A misconfigured hub (e.g. a public URL
        // on a different domain than the app) must degrade gracefully — never
        // take down the grid — so swallow the cookie error and skip real-time.
        $rt = $this->realtime();
        if ($rt['active']) {
            try {
                $this->mercureAuthorization()?->setCookie($request, [$rt['topic']]);
            } catch (\Throwable) {
                // Real-time stays off for this request; the grid renders normally.
            }
        }

        return $this->buildGridview()->renderGrid($this->config('indexTemplate'));
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $format = (string) $request->query->get('format', 'csv');
        $exporters = $this->exporters();
        if (!$exporters->has($format)) {
            throw $this->createNotFoundException();
        }

        $gridview = $this->buildGridview();

        return $exporters->get($format)->export(
            $gridview->getExportRows(),
            $gridview->getExportColumns(),
            ['filename' => $this->config('exportFilename') ?? $this->config('id')],
        );
    }

    // ---- internals -----------------------------------------------------

    protected function buildGridview(): Gridview
    {
        $exporters = $this->exporters();

        $rt = $this->realtime();

        $options = array_replace([
            'routeName' => $this->routeName('index'),
            'export' => [
                'url' => $this->generateUrl($this->routeName('export')),
                'formats' => array_map(
                    static fn($e) => ['key' => $e->getKey(), 'label' => $e->getLabel()],
                    array_values($exporters->all()),
                ),
            ],
            // Resolved real-time settings consumed by _grid.html.twig. `enabled`
            // is only true when both the grid opts in *and* a hub is available.
            'realtime' => [
                'enabled' => $rt['active'],
                'topic'   => $rt['topic'],
                'hubUrl'  => $rt['hubUrl'],
            ],
        ], $this->crudOptions(), $this->config('options'));

        return $this->builderFactory()->createGridviewBuilder()
            ->setId($this->config('id'))
            ->setSearchModel($this->searchModel())
            ->setDataProvider($this->getDataProviderConfig())
            ->setColumns($this->buildColumns())
            ->setOptions($options)
            ->setAttributes($this->config('attributes'))
            ->renderGridview();
    }

    /**
     * Route name for an action of THIS controller. The prefix is read once from
     * the concrete class's own `#[Route(name: ...)]` attribute, so subclasses
     * don't have to declare it twice.
     */
    protected function routeName(string $action): string
    {
        if ($this->routePrefix === null) {
            $this->routePrefix = '';
            foreach ((new \ReflectionClass($this))->getAttributes() as $attr) {
                if (str_ends_with($attr->getName(), '\\Route')) {
                    $instance = $attr->newInstance();
                    if (method_exists($instance, 'getName')) {
                        $this->routePrefix = $instance->getName() ?? '';
                    }
                    break;
                }
            }
        }

        return $this->routePrefix . $action;
    }

    /**
     * Resolved real-time config for this grid, computed once.
     *  - active:      grid opted in (YAML) AND a Mercure hub is available
     *  - topic:       the per-grid topic ("<prefix><id>")
     *  - topicPrefix: prefix used to build the topic
     *  - hubUrl:      public hub URL for the browser (null when inactive)
     *
     * @return array{active: bool, topic: string, topicPrefix: string, hubUrl: ?string}
     */
    protected function realtime(): array
    {
        if ($this->resolvedRealtime === null) {
            $rt = $this->container->get(GridviewConfigRegistry::class)
                ->resolveOptions($this->config('id'))['realtime'] ?? [];
            $rt += ['enabled' => false, 'topicPrefix' => 'gridview/'];

            $hub    = $this->mercureHub();
            $active = !empty($rt['enabled']) && $hub !== null;

            $this->resolvedRealtime = [
                'active'      => $active,
                'topic'       => ($rt['topicPrefix'] ?: 'gridview/') . $this->config('id'),
                'topicPrefix' => $rt['topicPrefix'] ?: 'gridview/',
                'hubUrl'      => $active ? $hub->getPublicUrl() : null,
            ];
        }

        return $this->resolvedRealtime;
    }

    protected function mercureHub(): ?HubInterface
    {
        return $this->container->has(HubInterface::class)
            ? $this->container->get(HubInterface::class)
            : null;
    }

    protected function mercureAuthorization(): ?Authorization
    {
        return $this->container->has(Authorization::class)
            ? $this->container->get(Authorization::class)
            : null;
    }

    protected function builderFactory(): GridviewBuilderFactory
    {
        return $this->container->get(GridviewBuilderFactory::class);
    }

    protected function exporters(): GridExporterRegistry
    {
        return $this->container->get(GridExporterRegistry::class);
    }

    protected function searchModel(): SearchModelInterface
    {
        return $this->container->get(SearchModelInterface::class);
    }

    protected function em(): EntityManagerInterface
    {
        return $this->container->get(EntityManagerInterface::class);
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            GridviewBuilderFactory::class,
            GridExporterRegistry::class,
            SearchModelInterface::class,
            EntityManagerInterface::class,
            GridviewConfigRegistry::class,
            // Optional: present only when symfony/mercure-bundle is installed.
            '?' . HubInterface::class,
            '?' . Authorization::class,
        ]);
    }
}
