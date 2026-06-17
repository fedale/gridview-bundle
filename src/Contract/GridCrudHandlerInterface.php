<?php

namespace Fedale\GridviewBundle\Contract;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Persistence boundary for grid-generated CRUD forms. The bundle owns form
 * building/rendering and Doctrine persistence; the host app owns the routes and
 * delegates to this handler (mirrors the persist/flush/CSRF pattern of a classic
 * Symfony CRUD controller).
 */
interface GridCrudHandlerInterface
{
    public const MODE_ADD   = 'add';
    public const MODE_EDIT  = 'edit';
    public const MODE_CLONE = 'clone';

    /**
     * Builds the form bound to the right data instance for the mode:
     *  - add: a fresh entity;
     *  - edit: the managed $entity (mutated in place on submit);
     *  - clone: a detached copy of $entity on GET, a fresh entity on POST.
     *
     * @param iterable<ColumnInterface> $columns
     */
    public function createForm(string $dataClass, iterable $columns, string $mode, ?object $entity, Request $request, array $options = []): FormInterface;

    /**
     * Renders the form HTML for the modal body, honoring an optional custom
     * layout view ($view) whose `{ attribute }` tokens are replaced by the
     * matching widget. When $view is null the form is rendered automatically.
     *
     * @param iterable<ColumnInterface> $columns
     */
    public function renderForm(FormInterface $form, iterable $columns, ?string $view = null, array $context = []): string;

    /**
     * Renders the form wrapped in a full-page template (crud.mode = page/custom).
     * $template is the page wrapper; the rendered form is passed as `formHtml`.
     *
     * @param iterable<ColumnInterface> $columns
     */
    public function renderFormPage(FormInterface $form, iterable $columns, ?string $view, string $template, array $context = []): string;

    /**
     * Persists a valid submitted form. persist() for add/clone, flush() always.
     * Returns the persisted entity, or null when a DB UNIQUE constraint was hit
     * (a form error is added and the caller should re-render the form).
     */
    public function save(FormInterface $form, string $mode): ?object;

    /**
     * Renders the delete-confirmation recap (a row summary + a CSRF-protected
     * POST form to $action) for the modal. Columns flagged `showInDeleteConfirm`
     * drive the recap; falls back to the first few visible columns.
     *
     * @param iterable<ColumnInterface> $columns
     */
    public function renderDeleteConfirm(object $entity, iterable $columns, string $action, string $token, array $context = []): string;

    /** Validates the CSRF token and removes the entity. Returns false on bad token. */
    public function delete(object $entity, ?string $token, string $csrfId): bool;

    /** Deletes every entity in $ids (clearing owning collections). Returns the count. */
    public function bulkDelete(string $dataClass, array $ids): int;

    /** Renders the bulk-delete confirmation (count + CSRF form) for the modal. */
    public function renderBulkDeleteConfirm(int $count, string $action, string $token, array $context = []): string;

    /** Builds the bulk batch-update form from the columns flagged `batchUpdate`. */
    public function createBatchForm(iterable $columns): FormInterface;

    /** Renders the batch-update form (built via createBatchForm) for the modal. */
    public function renderBatchForm(FormInterface $form, int $count, string $action, array $context = []): string;

    /**
     * Applies the checked batch fields to every entity in $ids. Returns the count.
     *
     * @param iterable<ColumnInterface> $columns
     */
    public function applyBatch(string $dataClass, array $ids, FormInterface $form, iterable $columns): int;

    /**
     * Whether another row of $dataClass already has $value in $field (optionally
     * excluding $excludeId, for edit). $field must be a mapped field. Backs the
     * live uniqueness check.
     */
    public function existsWithValue(string $dataClass, string $field, mixed $value, mixed $excludeId = null): bool;

    /** Renders the inline editor (single-field form) for a cell. */
    public function renderInlineEditor(string $dataClass, ColumnInterface $column, object $entity, string $action): string;

    /**
     * Validates + saves one inline-edited field.
     * @return array{ok: bool, body: string} body = new cell HTML (ok) or editor with errors
     */
    public function saveInline(string $dataClass, ColumnInterface $column, object $entity, Request $request, string $action): array;

    /** CSRF token id for deleting a given entity (e.g. `gridcrud_delete_42`). */
    public function deleteTokenId(object $entity): string;
}
