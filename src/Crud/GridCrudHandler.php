<?php

namespace Fedale\GridviewBundle\Crud;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Fedale\GridviewBundle\Contract\ColumnInterface;
use Fedale\GridviewBundle\Contract\GridCrudHandlerInterface;
use Fedale\GridviewBundle\Contract\GridFormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

class GridCrudHandler implements GridCrudHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GridFormBuilderInterface $formBuilder,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private Environment $twig,
        private ManagerRegistry $managerRegistry,
    ) {
    }

    public function createForm(string $dataClass, iterable $columns, string $mode, ?object $entity, Request $request, array $options = []): FormInterface
    {
        $isPost = $request->isMethod('POST');

        $data = match ($mode) {
            self::MODE_EDIT  => $entity,
            // On POST a clone behaves like add: a fresh instance the form rebinds.
            self::MODE_CLONE => $isPost
                ? new $dataClass()
                : $this->cloneEntity($entity, $dataClass, $options['cloneCallback'] ?? null),
            default          => new $dataClass(),
        };

        return $this->formBuilder->build($dataClass, $columns, $data, $options + ['mode' => $mode]);
    }

    public function renderForm(FormInterface $form, iterable $columns, ?string $view = null, array $context = []): string
    {
        $formView = $form->createView();

        $body = null;
        if ($view !== null) {
            $rawBody = $this->twig->render($view, array_merge($context, ['form' => $formView]));

            // Single-brace token replacement, consistent with the layout tokens
            // ({toolbar}, {header}, ...). Render each referenced field lazily so
            // only placed fields are marked rendered — fields with a control but
            // no token fall through to form_end() instead of vanishing. Unknown
            // tokens collapse to empty.
            $body = preg_replace_callback(
                '/\{\s*([\w.]+)\s*\}/',
                function (array $m) use ($formView): string {
                    $name = $m[1];
                    if (!isset($formView[$name])) {
                        return '';
                    }

                    return $this->twig->render(
                        '@FedaleGridview/crud/_field.html.twig',
                        ['field' => $formView[$name]]
                    );
                },
                $rawBody
            );
        }

        return $this->twig->render('@FedaleGridview/crud/_form_layout.html.twig', array_merge($context, [
            'form'     => $formView,
            'body'     => $body,
            'action'   => $context['action'] ?? '',
            'formAttr' => $context['formAttr'] ?? [],
        ]));
    }

    public function renderDeleteConfirm(object $entity, iterable $columns, string $action, string $token, array $context = []): string
    {
        $accessor = PropertyAccess::createPropertyAccessor();
        $columns  = \is_array($columns) ? $columns : iterator_to_array($columns);

        // Columns explicitly flagged; fallback to the first few visible columns.
        $flagged = array_filter($columns, static fn ($c) => $c instanceof ColumnInterface
            && $c->getShowInDeleteConfirm() !== false
            && $c->getShowInDeleteConfirm() !== []
            && $c->getAttribute() !== null);

        if ($flagged === []) {
            // Fallback: first few visible columns that carry a real attribute.
            $flagged = \array_slice(array_values(array_filter(
                $columns,
                static fn ($c) => $c instanceof ColumnInterface && $c->isVisible() && $c->getAttribute() !== null
            )), 0, 3);
        }

        $rows = [];
        foreach ($flagged as $column) {
            $attribute = $column->getAttribute();
            try {
                $value = $accessor->getValue($entity, $attribute);
            } catch (\Throwable) {
                continue;
            }
            $rows[] = ['label' => $column->getLabel() ?? $attribute, 'value' => $this->stringifyValue($value)];
        }

        return $this->twig->render('@FedaleGridview/crud/_delete.html.twig', array_merge($context, [
            'rows'   => $rows,
            'action' => $action,
            'token'  => $token,
        ]));
    }

    public function renderFormPage(FormInterface $form, iterable $columns, ?string $view, string $template, array $context = []): string
    {
        $formHtml = $this->renderForm($form, $columns, $view, ['page' => true] + $context);

        return $this->twig->render($template, ['formHtml' => $formHtml] + $context);
    }

    public function save(FormInterface $form, string $mode): ?object
    {
        $entity = $form->getData();

        if (\in_array($mode, [self::MODE_ADD, self::MODE_CLONE], true)) {
            $this->entityManager->persist($entity);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Safety net for DB-level UNIQUE constraints not covered by a
            // declared UniqueEntity. The EM closes on failure, so reset it (the
            // caller re-renders the form, whose EntityType fields need a live EM).
            $this->managerRegistry->resetManager();
            $form->addError(new FormError(
                'A record with these values already exists (unique constraint violated).'
            ));

            return null;
        }

        return $entity;
    }

    public function delete(object $entity, ?string $token, string $csrfId): bool
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($csrfId, (string) $token))) {
            return false;
        }

        $this->clearOwningCollections($entity);

        try {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        } catch (ForeignKeyConstraintViolationException) {
            // Still referenced elsewhere (e.g. a restrict FK). Reset the closed
            // EM so the caller can re-render the grid without a 500.
            $this->managerRegistry->resetManager();

            return false;
        }

        return true;
    }

    public function bulkDelete(string $dataClass, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $repo  = $this->entityManager->getRepository($dataClass);
        $count = 0;
        foreach ($ids as $id) {
            $entity = $repo->find($id);
            if ($entity === null) {
                continue;
            }
            $this->clearOwningCollections($entity);
            $this->entityManager->remove($entity);
            ++$count;
        }

        try {
            $this->entityManager->flush();
        } catch (ForeignKeyConstraintViolationException) {
            $this->managerRegistry->resetManager();

            return 0;
        }

        return $count;
    }

    public function renderBulkDeleteConfirm(int $count, string $action, string $token, array $context = []): string
    {
        return $this->twig->render('@FedaleGridview/crud/_bulk_delete.html.twig', array_merge($context, [
            'count'  => $count,
            'action' => $action,
            'token'  => $token,
        ]));
    }

    public function createBatchForm(iterable $columns): FormInterface
    {
        return $this->formBuilder->buildBatchForm($columns);
    }

    public function renderBatchForm(FormInterface $form, int $count, string $action, array $context = []): string
    {
        return $this->twig->render('@FedaleGridview/crud/_batch.html.twig', array_merge($context, [
            'form'   => $form->createView(),
            'count'  => $count,
            'action' => $action,
        ]));
    }

    /**
     * Applies the checked batch fields to every entity in $ids. Returns the count.
     *
     * @param iterable<\Fedale\GridviewBundle\Contract\ColumnInterface> $columns
     */
    public function applyBatch(string $dataClass, array $ids, FormInterface $form, iterable $columns): int
    {
        if ($ids === []) {
            return 0;
        }

        $data     = $form->getData();
        $accessor = PropertyAccess::createPropertyAccessor();

        // Collect the fields whose enable checkbox is checked.
        $changes = [];
        foreach ($columns as $column) {
            if (!$column instanceof ColumnInterface || !$column->isBatchUpdate()) {
                continue;
            }
            $attribute = $column->getAttribute();
            if (!empty($data['enable_' . $attribute])) {
                $changes[$attribute] = $data[$attribute] ?? null;
            }
        }

        if ($changes === []) {
            return 0;
        }

        $repo  = $this->entityManager->getRepository($dataClass);
        $count = 0;
        foreach ($ids as $id) {
            $entity = $repo->find($id);
            if ($entity === null) {
                continue;
            }
            foreach ($changes as $attribute => $value) {
                if ($accessor->isWritable($entity, $attribute)) {
                    $accessor->setValue($entity, $attribute, $value);
                }
            }
            ++$count;
        }

        $this->entityManager->flush();

        return $count;
    }

    /**
     * Clears owning-side ManyToMany collections so their join-table rows are
     * removed before the entity DELETE (otherwise the FK blocks it).
     */
    private function clearOwningCollections(object $entity): void
    {
        $meta = $this->entityManager->getClassMetadata($entity::class);
        foreach ($meta->getAssociationNames() as $field) {
            if ($meta->isCollectionValuedAssociation($field) && !$meta->isAssociationInverseSide($field)) {
                $collection = $meta->getFieldValue($entity, $field);
                if ($collection instanceof Collection) {
                    $collection->clear();
                }
            }
        }
    }

    public function existsWithValue(string $dataClass, string $field, mixed $value, mixed $excludeId = null): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $em   = $this->managerRegistry->getManagerForClass($dataClass) ?? $this->entityManager;
        $meta = $em->getClassMetadata($dataClass);

        // Only allow real mapped fields — defensive even if the caller forgot to
        // whitelist, so $field can't be injected into the DQL.
        if (!\in_array($field, $meta->getFieldNames(), true)) {
            return false;
        }

        $qb = $em->createQueryBuilder()
            ->select('COUNT(e)')
            ->from($dataClass, 'e')
            ->where("e.$field = :value")
            ->setParameter('value', $value);

        if ($excludeId !== null && $excludeId !== '') {
            $idField = $meta->getSingleIdentifierFieldName();
            $qb->andWhere("e.$idField <> :excludeId")->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function renderInlineEditor(string $dataClass, ColumnInterface $column, object $entity, string $action): string
    {
        $form = $this->formBuilder->build($dataClass, [$column], $entity, ['name' => 'inline', 'submit' => false]);

        return $this->renderInlineForm($form, $column, $action);
    }

    /**
     * Validates and saves a single inline-edited field. Returns
     * ['ok' => bool, 'body' => string] — on success the new cell display HTML,
     * otherwise the editor re-rendered with errors.
     */
    public function saveInline(string $dataClass, ColumnInterface $column, object $entity, Request $request, string $action): array
    {
        $form = $this->formBuilder->build($dataClass, [$column], $entity, ['name' => 'inline', 'submit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $this->managerRegistry->resetManager();

                return ['ok' => false, 'body' => $this->renderInlineForm($form, $column, $action)];
            }

            $value = PropertyAccess::createPropertyAccessor()->getValue($entity, $column->getAttribute());

            return ['ok' => true, 'body' => $this->stringifyValue($value)];
        }

        return ['ok' => false, 'body' => $this->renderInlineForm($form, $column, $action)];
    }

    private function renderInlineForm(FormInterface $form, ColumnInterface $column, string $action): string
    {
        return $this->twig->render('@FedaleGridview/crud/_inline.html.twig', [
            'form'   => $form->createView(),
            'field'  => $column->getAttribute(),
            'action' => $action,
        ]);
    }

    public function deleteTokenId(object $entity): string
    {
        $meta = $this->entityManager->getClassMetadata($entity::class);
        $id   = $meta->getIdentifierValues($entity);

        return 'gridcrud_delete_' . implode('_', $id);
    }

    /** Best-effort human-readable rendering of a recap value. */
    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (\is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y H:i');
        }
        if ($value instanceof Collection || \is_array($value)) {
            $items = $value instanceof Collection ? $value->toArray() : $value;

            return implode(', ', array_map(fn ($v) => $this->stringifyValue($v), $items));
        }
        if (\is_object($value)) {
            if (method_exists($value, 'getName')) {
                return (string) $value->getName();
            }
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return $value::class;
        }

        return (string) $value;
    }

    /**
     * Clone with a reset identifier. To-many associations are given their own
     * new collection (pointing to the same related entities) so the clone is
     * independent of the source — a shallow clone would share the very same
     * Collection object. Supply a cloneCallback(object $clone, object $source)
     * to reset unique fields or further customize.
     */
    private function cloneEntity(object $entity, string $dataClass, ?callable $cloneCallback): object
    {
        $clone = clone $entity;

        $meta = $this->entityManager->getClassMetadata($dataClass);
        foreach ($meta->getIdentifierFieldNames() as $idField) {
            $meta->setFieldValue($clone, $idField, null);
        }

        // Deep-copy collection associations: a shallow clone shares the source's
        // Collection instance, so add/remove on the clone would mutate the source.
        foreach ($meta->getAssociationNames() as $field) {
            if (!$meta->isCollectionValuedAssociation($field)) {
                continue;
            }
            $source = $meta->getFieldValue($entity, $field);
            $elements = $source instanceof Collection ? $source->toArray() : (array) $source;
            $meta->setFieldValue($clone, $field, new ArrayCollection($elements));
        }

        if ($cloneCallback !== null) {
            $cloneCallback($clone, $entity);
        }

        return $clone;
    }
}
