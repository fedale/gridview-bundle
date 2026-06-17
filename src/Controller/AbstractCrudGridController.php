<?php

namespace Fedale\GridviewBundle\Controller;

use Fedale\GridviewBundle\Contract\GridCrudHandlerInterface;
use Fedale\GridviewBundle\Mercure\GridviewMercurePublisher;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Annotation\Route;
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
     * Adds the CRUD-specific config keys on top of the read-only defaults:
     *  - title:          modal/page title
     *  - mode:           'modal' | 'page' | 'custom'
     *  - formView:       custom form layout; null = automatic rendering
     *  - pageTemplate:   full-page wrapper for page/custom; null = bundle default
     *  - addLabel:       label of the "add" toolbar button
     *  - filterFormName: query key of the filter form (for "all" bulk ids)
     */
    protected function defaultConfig(): array
    {
        return array_replace(parent::defaultConfig(), [
            'title'          => '',
            'mode'           => 'modal',
            'formView'       => null,
            'pageTemplate'   => null,
            'addLabel'       => 'New',
            'filterFormName' => 'myform',
        ]);
    }

    // ---- hooks ---------------------------------------------------------

    /** Runs on a valid submitted add/edit/clone form, before persistence (e.g. password hashing). */
    protected function beforeSave(FormInterface $form, string $mode): void
    {
    }

    /** Extra mutation of a freshly cloned entity (unique fields are already cleared). */
    protected function onClone(object $clone): void
    {
    }

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
            return new Response($crud->renderDeleteConfirm(
                $entity,
                $this->buildGridview()->getColumns(),
                $this->generateUrl($this->routeName('delete'), ['id' => $id]),
                $this->csrf()->getToken($crud->deleteTokenId($entity))->getValue(),
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
            ));
        }

        if ($this->isCsrfTokenValid('gridcrud_bulk_delete', (string) $request->request->get('_token'))
            && $this->crud()->bulkDelete($this->getDataClass(), $ids) > 0) {
            $this->publishRealtime('delete');
        }

        return $this->bulkStream();
    }

    #[Route('/bulk/update', name: 'bulk_update', methods: ['GET', 'POST'])]
    public function bulkUpdate(Request $request): Response
    {
        $ids = $this->resolveBulkIds($request);
        $columns = $this->buildGridview()->getColumns();
        $form = $this->crud()->createBatchForm($columns);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->crud()->applyBatch($this->getDataClass(), $ids, $form, $columns) > 0) {
                $this->publishRealtime('update');
            }

            return $this->bulkStream();
        }

        return new Response($this->crud()->renderBatchForm($form, \count($ids), $request->getRequestUri()));
    }

    #[Route('/inline/{id}/{field}', name: 'inline', methods: ['GET', 'POST'], requirements: ['id' => '\d+', 'field' => '[a-zA-Z_]+'])]
    public function inline(Request $request, int $id, string $field): Response
    {
        $entity = $this->em()->getRepository($this->getDataClass())->find($id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }

        // Only columns explicitly marked editable may be edited inline.
        $column = null;
        foreach ($this->buildGridview()->getColumns() as $c) {
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
            return new Response($this->crud()->renderInlineEditor($this->getDataClass(), $column, $entity, $action));
        }

        $result = $this->crud()->saveInline($this->getDataClass(), $column, $entity, $request, $action);

        if ($result['ok']) {
            $this->publishRealtime('update');
        }

        return new Response($result['body'], $result['ok'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // ---- internals -----------------------------------------------------

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

        $form = $crud->createForm($dataClass, $columns, $mode, $entity, $request, [
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

        // Modal requests are XHR (gridview-crud fetch); direct navigation is the
        // full-page form. This drives both the response and the submit behavior.
        $isXhr = $request->isXmlHttpRequest();

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

                return $this->redirectToRoute($this->routeName('index'));
            }
        }

        $context = [
            'action' => $request->getRequestUri(),
            'mode' => $mode,
            'validate' => [
                'checkUrl' => $this->generateUrl($this->routeName('exists')),
                'unique' => $uniqueFields,
                // Only exclude the current row in edit; a clone is a new record.
                'id' => $mode === GridCrudHandlerInterface::MODE_EDIT ? $id : null,
                'formName' => 'gridform',
            ],
        ];

        if ($isXhr) {
            return new Response($crud->renderForm($form, $columns, $this->config('formView'), $context));
        }

        // Full page (crud.mode = page/custom, or no-JS fallback for modal).
        $template = $this->config('pageTemplate') ?? '@FedaleGridview/crud/page.html.twig';

        return new Response($crud->renderFormPage($form, $columns, $this->config('formView'), $template, $context + [
            'pageTitle' => $this->config('title'),
        ]));
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
            $params = $request->query->all($this->config('filterFormName'));
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
                'title' => $this->config('title'),
                'mode' => $this->config('mode'),
                'pageTemplate' => $this->config('pageTemplate'),
                'addUrl' => $this->generateUrl($this->routeName('new')),
                'bulkDeleteUrl' => $this->generateUrl($this->routeName('bulk_delete')),
                'bulkUpdateUrl' => $this->generateUrl($this->routeName('bulk_update')),
                // Base for inline editing; the JS appends /{id}/{field}.
                'inlineUrl' => $this->generateUrl($this->routeName('index')) . '/inline',
            ],
            'addLabel' => $this->config('addLabel'),
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
