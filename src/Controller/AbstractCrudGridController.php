<?php

namespace Fedale\GridviewBundle\Controller;

use Fedale\GridviewBundle\Contract\GridCrudHandlerInterface;
use Fedale\GridviewBundle\Crud\CrudButton;
use Fedale\GridviewBundle\Grid\GridviewConfigRegistry;
use Fedale\GridviewBundle\Mercure\GridviewMercurePublisher;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Full CRUD grid controller base. Adds the add/edit/clone/delete, bulk and
 * inline-edit actions on top of {@see AbstractGridController}, all delegating to
 * the {@see GridCrudHandlerInterface}. The form is generated from the columns'
 * `control` config — no hand-written FormType. The live-uniqueness whitelist and
 * the clone field-clearing are derived automatically from the columns flagged
 * `control.unique`.
 */
abstract class AbstractCrudGridController extends AbstractGridController
{
    /**
     * Adds the CRUD-specific config groups on top of the read-only defaults.
     * Label keys default to null and are derived from the grid id by
     * {@see applyConventions()} (e.g. `tag.label`, `tag.add`).
     *
     *  - labels.heading: grid/page/modal title (null → `{id}.label`)
     *  - labels.add:     "add" toolbar button + new/clone page title (null → `{id}.add`)
     *  - labels.edit:    edit-form page title (null → fall back to `labels.heading`)
     *  - form.mode:      'modal' | 'page' | 'custom' — how/where the form is shown
     *  - form.theme:     Symfony form theme(s) for the CRUD form (null = default)
     *  - form.view:      custom form layout; null = automatic rendering
     *  - form.actions:   header/inline action buttons in a token layout. See
     *                    resolveFormActions(). `placement` 'header' drops the
     *                    in-form submit; `layout` orders the named `buttons`.
     *  - form.filterName: query key of the filter form (for "all" bulk ids)
     *  - template.page:  full-page wrapper for page/custom; null = bundle default
     *
     * The action-column token layout lives in `options.actionLayout` (null = fall
     * back to YAML `gridviews.<id>.options.actionLayout`, then the built-in default).
     */
    protected function defaultConfig(): array
    {
        return $this->mergeConfig(parent::defaultConfig(), [
            'labels'   => ['heading' => null, 'add' => null, 'edit' => null],
            'form'     => [
                'mode'       => 'modal',
                'theme'      => null,
                'view'       => null,
                'actions'    => ['placement' => 'inline', 'layout' => null, 'buttons' => null],
                'filterName' => 'fedaleForm',
            ],
            'template' => ['page' => null],
        ]);
    }

    /** Derives the label keys from the grid id by convention, then the base export filename. */
    protected function applyConventions(array $resolved): array
    {
        $id = $resolved['id'] ?? null;
        if ($id !== null) {
            $resolved['labels']['heading'] ??= $id . '.label';
            $resolved['labels']['add'] ??= $id . '.add';
        }
        // Edit title falls back to the heading rather than inventing a `.edit` key.
        $resolved['labels']['edit'] ??= $resolved['labels']['heading'] ?? null;

        return parent::applyConventions($resolved);
    }

    // ---- hooks ---------------------------------------------------------

    /** Runs on a valid submitted add/edit/clone form, before persistence (e.g. password hashing). */
    protected function beforeSave(FormInterface $form, string $mode): void {}

    /** Extra mutation of a freshly cloned entity (unique fields are already cleared). */
    protected function onClone(object $clone): void {}

    // ---- actions: add / edit / clone -----------------------------------

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handleForm($request, GridCrudHandlerInterface::MODE_ADD, null);
    }

    #[Route('/update/{id}', name: 'update', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function update(Request $request, int $id): Response
    {
        return $this->handleForm($request, GridCrudHandlerInterface::MODE_EDIT, $id);
    }

    #[Route('/clone/{id}', name: 'clone', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function cloneRecord(Request $request, int $id): Response
    {
        return $this->handleForm($request, GridCrudHandlerInterface::MODE_CLONE, $id);
    }

    /** Live uniqueness check used by the gridview-form-validate Stimulus controller. */
    #[Route('/exists', name: 'exists', methods: ['GET'])]
    public function exists(Request $request): JsonResponse
    {
        $field = (string) $request->query->get('field');

        // Whitelist = the fields flagged unique in the column controls.
        if (!\in_array($field, $this->uniqueFields($this->buildGridview()->getColumns()), true)) {
            return new JsonResponse(['exists' => false]);
        }

        return new JsonResponse([
            'exists' => $this->crud()->existsWithValue(
                $this->getDataClass(),
                $field,
                $request->query->get('value'),
                $request->query->get('id'),
            ),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $crud = $this->crud();
        $entity = $this->em()->getRepository($this->getDataClass())->find($id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }

        // GET → render the confirmation recap into the modal.
        if ($request->isMethod('GET')) {
            // Post back to the current URI (same route handles GET+POST) so the
            // grid's filter/sort/page query — forwarded by the JS on open — is
            // preserved and re-applied when the post-delete stream rebuilds the grid.
            $gridview = $this->buildGridview();

            return new Response($crud->renderDeleteConfirm(
                $entity,
                $gridview->getColumns(),
                $request->getRequestUri(),
                $this->csrf()->getToken($crud->deleteTokenId($entity))->getValue(),
                ['gridview' => $gridview],
            ));
        }

        // POST → delete and refresh the grid via Turbo Stream.
        if ($crud->delete($entity, $request->request->get('_token'), $crud->deleteTokenId($entity))) {
            $this->publishRealtime('delete');
        }

        return $this->bulkStream();
    }

    #[Route('/bulk/delete', name: 'bulk_delete', methods: ['GET', 'POST'])]
    public function bulkDelete(Request $request): Response
    {
        $ids = $this->resolveBulkIds($request);

        if ($request->isMethod('GET')) {
            return new Response($this->crud()->renderBulkDeleteConfirm(
                \count($ids),
                $request->getRequestUri(),
                $this->csrf()->getToken('gridcrud_bulk_delete')->getValue(),
                ['gridview' => $this->buildGridview()],
            ));
        }

        if (
            $this->isCsrfTokenValid('gridcrud_bulk_delete', (string) $request->request->get('_token'))
            && $this->crud()->bulkDelete($this->getDataClass(), $ids) > 0
        ) {
            $this->publishRealtime('delete');
        }

        return $this->bulkStream();
    }

    #[Route('/bulk/update', name: 'bulk_update', methods: ['GET', 'POST'])]
    public function bulkUpdate(Request $request): Response
    {
        $ids = $this->resolveBulkIds($request);
        $gridview = $this->buildGridview();
        $columns = $gridview->getColumns();
        $form = $this->crud()->createBatchForm($columns);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->crud()->applyBatch($this->getDataClass(), $ids, $form, $columns) > 0) {
                $this->publishRealtime('update');
            }

            return $this->bulkStream();
        }

        return new Response($this->crud()->renderBatchForm($form, \count($ids), $request->getRequestUri(), ['gridview' => $gridview]));
    }

    #[Route('/inline/{id}/{field}', name: 'inline', methods: ['GET', 'POST'], requirements: ['id' => '\d+', 'field' => '[a-zA-Z_]+'])]
    public function inline(Request $request, int $id, string $field): Response
    {
        $entity = $this->em()->getRepository($this->getDataClass())->find($id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }

        // Only columns explicitly marked editable may be edited inline.
        $gridview = $this->buildGridview();
        $column = null;
        foreach ($gridview->getColumns() as $c) {
            if ($c->getAttribute() === $field && $c->isEditable()) {
                $column = $c;
                break;
            }
        }
        if ($column === null) {
            throw $this->createNotFoundException();
        }

        $action = $this->generateUrl($this->routeName('inline'), ['id' => $id, 'field' => $field]);

        if ($request->isMethod('GET')) {
            return new Response($this->crud()->renderInlineEditor($this->getDataClass(), $column, $entity, $action, ['gridview' => $gridview]));
        }

        $result = $this->crud()->saveInline($this->getDataClass(), $column, $entity, $request, $action, ['gridview' => $gridview]);

        if ($result['ok']) {
            $this->publishRealtime('update');
        }

        return new Response($result['body'], $result['ok'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // ---- internals -----------------------------------------------------

    /**
     * Auto-wires a bare `['type' => 'action']` column to the CRUD routes so it
     * works out of the box: no hand-written `CrudButton` closures needed. A
     * column that already declares its own `buttons` is left untouched. The token
     * layout defaults to `actionLayout` (config or YAML) and only tokens whose
     * convention route exists get a button — so read-only/missing routes render
     * nothing instead of dead links.
     *
     * @return array<int, mixed>
     */
    protected function gridColumns(): array
    {
        $columns = $this->buildColumns();
        $buttons = null;

        foreach ($columns as &$spec) {
            if (!\is_array($spec) || ($spec['type'] ?? null) !== 'action' || \array_key_exists('buttons', $spec)) {
                continue;
            }
            $buttons ??= $this->defaultActionButtons();
            if ($buttons === []) {
                continue;
            }
            $spec['layout'] ??= $this->actionLayout();
            $spec['buttons'] = $buttons;
        }
        unset($spec);

        return $columns;
    }

    /**
     * Default action-button closures keyed by token, one per convention CRUD route
     * that actually exists. Mirrors the manual pattern (`CrudButton::edit(...)`).
     *
     * @return array<string, \Closure>
     */
    protected function defaultActionButtons(): array
    {
        $mode = $this->config('form.mode');
        $buttons = [];

        if ($this->routeExists($this->routeName('show'))) {
            $buttons['view'] = fn(array $row) => CrudButton::view(
                $this->generateUrl($this->routeName('show'), ['id' => $row['id']])
            );
        }
        if ($this->routeExists($this->routeName('update'))) {
            $buttons['edit'] = fn(array $row) => CrudButton::edit(
                $this->generateUrl($this->routeName('update'), ['id' => $row['id']]),
                $mode
            );
        }
        if ($this->routeExists($this->routeName('clone'))) {
            $buttons['clone'] = fn(array $row) => CrudButton::clone(
                $this->generateUrl($this->routeName('clone'), ['id' => $row['id']]),
                $mode
            );
        }
        if ($this->routeExists($this->routeName('delete'))) {
            $buttons['delete'] = fn(array $row) => CrudButton::delete(
                $this->generateUrl($this->routeName('delete'), ['id' => $row['id']])
            );
        }

        return $buttons;
    }

    /** Resolved default action-token layout: PHP config wins, else YAML, else the built-in default. */
    private function actionLayout(): string
    {
        $configured = $this->config('options.actionLayout');
        if (\is_string($configured) && $configured !== '') {
            return $configured;
        }

        $yaml = $this->container->get(GridviewConfigRegistry::class)
            ->resolveOptions($this->config('id'))['actionLayout'] ?? null;

        return \is_string($yaml) && $yaml !== '' ? $yaml : '{view} {edit} {delete}';
    }

    /**
     * Shared add/edit/clone handler. XHR (modal) → partial/Turbo Stream; direct
     * navigation → full page + redirect on submit.
     */
    protected function handleForm(Request $request, string $mode, ?int $id): Response
    {
        $dataClass = $this->getDataClass();
        $crud = $this->crud();

        $entity = null;
        if ($id !== null) {
            $entity = $this->em()->getRepository($dataClass)->find($id);
            if ($entity === null) {
                throw $this->createNotFoundException();
            }
        }

        $gridview = $this->buildGridview();
        $columns = $gridview->getColumns();
        $uniqueFields = $this->uniqueFields($columns);

        // Modal requests are XHR (gridview-crud fetch); direct navigation is the
        // full-page form. When the page hoists the form actions into its header
        // (formActionsInHeader), the form renders no in-form submit button — the
        // page template provides an external one wired via the `form="…"` attr.
        $isXhr = $request->isXmlHttpRequest();
        $headerActions = !$isXhr && $this->config('form.actions.placement') === 'header';
        // The header action buttons for this mode (drives both the post-save
        // redirect branch below and the page template's button row).
        $formActions = $headerActions ? $this->resolveFormActions($mode) : [];

        $form = $crud->createForm($dataClass, $columns, $mode, $entity, $request, [
            'submit' => !$headerActions,
            'cloneCallback' => function (object $clone) use ($uniqueFields): void {
                // Unique fields must differ on a clone; collections stay prefilled.
                $accessor = PropertyAccess::createPropertyAccessor();
                foreach ($uniqueFields as $field) {
                    if ($accessor->isWritable($clone, $field)) {
                        $accessor->setValue($clone, $field, '');
                    }
                }
                $this->onClone($clone);
            },
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->beforeSave($form, $mode);

            // save() returns null when a DB UNIQUE constraint slipped past
            // validation; fall through to re-render the form with the error.
            if ($crud->save($form, $mode) !== null) {
                $this->publishRealtime($mode === GridCrudHandlerInterface::MODE_EDIT ? 'update' : 'create');

                if ($isXhr) {
                    $response = $gridview->renderGrid('@FedaleGridview/gridview/sections/_stream.html.twig');
                    $response->headers->set('Content-Type', 'text/vnd.turbo-stream.html');

                    return $response;
                }

                $this->addFlash('success', 'Record saved.');

                // The clicked submit button posts its `_action` value (e.g.
                // save_add_another); redirect to that descriptor's target route,
                // defaulting to the index (also covers an Enter-key submit).
                $submitted = (string) $request->request->get('_action', 'save');
                $redirect = 'index';
                foreach ($formActions as $a) {
                    if (($a['type'] ?? 'submit') === 'submit' && ($a['action'] ?? 'save') === $submitted) {
                        $redirect = $a['redirect'] ?? 'index';
                        break;
                    }
                }

                return $this->redirectToRoute($this->routeName($redirect));
            }
        }

        $context = [
            'action' => $request->getRequestUri(),
            'mode' => $mode,
            // Symfony form theme(s) applied to the rendered CRUD form (e.g.
            // ['bootstrap_5_layout.html.twig']); null = default form theme.
            'formTheme' => $this->config('form.theme'),
            'validate' => [
                'checkUrl' => $this->generateUrl($this->routeName('exists')),
                'unique' => $uniqueFields,
                // Only exclude the current row in edit; a clone is a new record.
                'id' => $mode === GridCrudHandlerInterface::MODE_EDIT ? $id : null,
                'formName' => 'gridform',
            ],
        ];

        if ($isXhr) {
            return new Response($crud->renderForm($form, $columns, $this->config('form.view'), $context));
        }

        // Full page (form.mode = page/custom, or no-JS fallback for modal).
        $template = $this->config('template.page') ?? '@FedaleGridview/crud/page.html.twig';

        return new Response($crud->renderFormPage($form, $columns, $this->config('form.view'), $template, $context + [
            // The page heading follows the action: the "add" label for new/clone,
            // the "edit" label (falling back to the heading) for an update.
            'pageTitle' => $mode === GridCrudHandlerInterface::MODE_EDIT
                ? $this->config('labels.edit')
                : $this->config('labels.add'),
            // The page template renders the external submit button (form="formId")
            // when the form drops its in-form button (formActionsInHeader).
            'formActionsInHeader' => $headerActions,
            'formActions' => $formActions,
            'formId' => $form->getName(),
        ]));
    }

    /**
     * The CRUD form action buttons for the current mode, in the order given by
     * the `form.actions.layout` token string (e.g. `{returnListing} {save}`). The
     * named descriptors come from `form.actions.buttons`; when unset, a single
     * primary "save" button (→ index) preserves the default behavior, and an
     * unset layout falls back to the buttons' declaration order. Each descriptor
     * is normalized for the page template:
     *  - a token whose button isn't defined is skipped;
     *  - descriptors with a `modes` whitelist not matching `$mode` are dropped;
     *  - a per-mode `label` map (e.g. ['add' => 'crud.create', 'edit' => 'crud.save'])
     *    is flattened to the key for `$mode`;
     *  - `link` descriptors get a pre-generated `url` (from `routeName(route)`), so
     *    the template needs no routing logic.
     *
     * @return list<array<string, mixed>>
     */
    protected function resolveFormActions(string $mode): array
    {
        $buttons = $this->config('form.actions.buttons') ?? [
            'save' => [
                'type' => 'submit',
                'action' => 'save',
                'redirect' => 'index',
                'variant' => 'primary',
                'label' => ['add' => 'crud.create', 'clone' => 'crud.create', 'edit' => 'crud.save'],
            ],
        ];

        $layout = $this->config('form.actions.layout');
        $tokens = \is_string($layout) && $layout !== ''
            ? (preg_match_all('/\{(\w+)\}/', $layout, $m) ? $m[1] : [])
            : array_keys($buttons);

        $resolved = [];
        foreach ($tokens as $token) {
            $action = $buttons[$token] ?? null;
            if ($action === null) {
                continue;
            }

            $modes = $action['modes'] ?? null;
            if ($modes !== null && !\in_array($mode, $modes, true)) {
                continue;
            }

            $label = $action['label'] ?? '';
            if (\is_array($label)) {
                $label = $label[$mode] ?? (reset($label) ?: '');
            }
            $action['label'] = $label;

            if (($action['type'] ?? 'submit') === 'link' && isset($action['route'])) {
                $action['url'] = $this->generateUrl($this->routeName($action['route']));
            }

            $resolved[] = $action;
        }

        return $resolved;
    }

    /**
     * Resolves the target ids: explicit `ids[]` from the query, or all-mode
     * (`all=1`) resolved server-side by re-running the filtered search.
     *
     * @return int[]
     */
    protected function resolveBulkIds(Request $request): array
    {
        if ($request->query->getBoolean('all')) {
            $params = $request->query->all($this->config('form.filterName'));
            $qb = $this->em()->getRepository($this->getDataClass())->search($params);
            $alias = $qb->getRootAliases()[0];
            $qb->select("DISTINCT {$alias}.id")->setFirstResult(null)->setMaxResults(null);

            return array_map(static fn(array $row) => (int) $row['id'], $qb->getQuery()->getScalarResult());
        }

        return array_map('intval', (array) $request->query->all('ids'));
    }

    protected function bulkStream(): Response
    {
        $response = $this->buildGridview()->renderGrid('@FedaleGridview/gridview/sections/_stream.html.twig');
        $response->headers->set('Content-Type', 'text/vnd.turbo-stream.html');

        return $response;
    }

    /**
     * The column attributes flagged `control.unique` — drives both the
     * live-uniqueness whitelist and the clear-on-clone behavior.
     *
     * @param iterable<\Fedale\GridviewBundle\Contract\ColumnInterface> $columns
     * @return string[]
     */
    protected function uniqueFields(iterable $columns): array
    {
        $fields = [];
        foreach ($columns as $column) {
            $control = $column->getControl();
            if ($control !== null && ($control['unique'] ?? null) !== null && $column->getAttribute() !== null) {
                $fields[] = $column->getAttribute();
            }
        }

        return $fields;
    }

    protected function crudOptions(): array
    {
        return [
            'crud' => [
                'title' => $this->config('labels.heading'),
                'mode' => $this->config('form.mode'),
                'pageTemplate' => $this->config('template.page'),
                'addUrl' => $this->generateUrl($this->routeName('new')),
                'bulkDeleteUrl' => $this->generateUrl($this->routeName('bulk_delete')),
                'bulkUpdateUrl' => $this->generateUrl($this->routeName('bulk_update')),
                // Base for inline editing; the JS appends /{id}/{field}.
                'inlineUrl' => $this->generateUrl($this->routeName('index')) . '/inline',
            ],
            'addLabel' => $this->config('labels.add'),
            // Default CRUD toolbar: add button + global search on the left, the
            // column-visibility and export controls pushed to the right by the
            // elastic {spacer}. The `header` region wraps the toolbar; a controller
            // can override layout.toolbar to change it.
            'layout' => [
                'toolbar' => '{addButton} {globalSearch} {spacer} {savedSearch} {columnVisibility} {export}',
            ],
        ];
    }

    /**
     * Broadcasts a real-time "grid changed" signal after a successful write.
     * No-op unless the grid opted into real-time and a Mercure hub is available.
     */
    protected function publishRealtime(string $action): void
    {
        $rt = $this->realtime();
        if (!$rt['active']) {
            return;
        }

        $this->mercurePublisher()->publish($this->config('id'), $action, $rt['topicPrefix']);
    }

    protected function mercurePublisher(): GridviewMercurePublisher
    {
        return $this->container->get(GridviewMercurePublisher::class);
    }

    protected function crud(): GridCrudHandlerInterface
    {
        return $this->container->get(GridCrudHandlerInterface::class);
    }

    protected function csrf(): CsrfTokenManagerInterface
    {
        return $this->container->get(CsrfTokenManagerInterface::class);
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            GridCrudHandlerInterface::class,
            CsrfTokenManagerInterface::class,
            GridviewMercurePublisher::class,
        ]);
    }
}
