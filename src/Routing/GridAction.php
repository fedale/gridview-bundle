<?php

namespace Fedale\GridviewBundle\Routing;

/**
 * Canonical vocabulary of the grid's CRUD actions, and the single source of
 * truth for the route-name suffixes used by the convention.
 *
 * A concrete grid controller carries one class-level `#[Route(name: '<prefix>')]`;
 * the full route name for an action is `<prefix>` + this enum's `value`
 * (see {@see \Fedale\GridviewBundle\Controller\AbstractGridController::routeName()}).
 *
 * The `#[Route]` attributes that materialise these routes live on the base
 * controllers and are inherited by the subclass (routes stay app-owned):
 *
 *   action        value          default path            method       provided by
 *   ------------  -------------  ----------------------  -----------  -----------------------------
 *   Index         index          '' (list root)          GET          AbstractGridController
 *   Export        export         /export                 GET          AbstractGridController
 *   Create        create         /new                    GET, POST    AbstractCrudGridController
 *   Update        update         /update/{id}            GET, POST    AbstractCrudGridController
 *   Clone         clone          /clone/{id}             GET, POST    AbstractCrudGridController
 *   Delete        delete         /{id}/delete            GET, POST    AbstractCrudGridController
 *   BulkDelete    bulk_delete    /bulk/delete            GET, POST    AbstractCrudGridController
 *   BulkUpdate    bulk_update    /bulk/update            GET, POST    AbstractCrudGridController
 *   Inline        inline         /inline/{id}/{field}    GET, POST    AbstractCrudGridController
 *   Exists        exists         /exists                 GET          AbstractCrudGridController
 *   Show          show           /{id}                   GET          AbstractDetailController
 *
 * `Show` is the operation/route name (Sylius/REST convention) that the `{show}`
 * action-column button wires to via `routeName('show')`, guarded by
 * `routeExists()`. It's not on the CRUD controller — the detail page ships on
 * {@see \Fedale\GridviewBundle\Controller\AbstractDetailController}; give that
 * controller the grid's name prefix and the `{show}` button lights up on its own.
 *
 * `Create` keeps the `/new` path (the form URL, as in Sylius) but the REST
 * operation name — pairing symmetrically with `Update`; both routes serve the
 * form (GET) and its submit (POST).
 */
enum GridAction: string
{
    case Index = 'index';
    case Export = 'export';
    case Create = 'create';
    case Update = 'update';
    case Clone = 'clone';
    case Delete = 'delete';
    case BulkDelete = 'bulk_delete';
    case BulkUpdate = 'bulk_update';
    case Inline = 'inline';
    case Exists = 'exists';
    case Show = 'show';
}
