# Real-time updates (Mercure)

When a user creates, edits or deletes a record, **everyone else viewing the same grid**
can see the change on-the-fly. The grid auto-refreshes and shows a short toast — no manual
reload needed.

This is **opt-in per grid** and requires [`symfony/mercure-bundle`](https://symfony.com/doc/current/mercure.html).
Without it, the bundle still boots and the feature is simply inert.

## How it works (signal + auto-refresh)

```
user-2: POST /gridview/user/update/42
   └─ AbstractCrudGridController → save() OK
        └─ publish a PRIVATE Mercure update to topic "gridview/user"
             payload = {"gridId":"user","action":"update"}   ← no row data
user-1 (already on the grid):
   gridview-mercure Stimulus controller (EventSource, withCredentials)
     └─ on message → re-submits the filter form (debounced) + shows a toast
```

The published message carries **only** `gridId` + `action`, never row HTML or field
values. Each observer reacts by **refetching the grid through its own request**, so the
server re-applies that user's filters, pagination and authorization. Consequences:

- No data a user isn't allowed to see can leak through the stream.
- The refreshed rows always match the observer's **current filter/page**, not the editor's.

## Topic per grid

Events are scoped to one topic per grid: `<topicPrefix><gridId>` (default
`gridview/<id>`). The `<id>` is the grid id — the entity short name lowercased
(e.g. `User` → `user`), or whatever a controller sets via `viewConfig()`'s `id`.
Subscriptions are therefore limited to the single grid.

## Private topics

Topics are **private**: on render, the controller sets a `mercureAuthorization` cookie
(JWT) granting `subscribe` to **only the current grid's topic**, and only for a user who
could open the grid (same firewall / access control as the index route). The browser sends
that cookie when opening the `EventSource` (`withCredentials`).

> A misconfigured hub never takes the grid down: the cookie call is guarded, so on failure
> the grid renders normally with real-time simply off.

## Enabling it

1. Install the hub support:

   ```bash
   composer require symfony/mercure-bundle
   ```

2. Turn it on for the grids that need it, in `config/packages/gridview.yaml`:

   ```yaml
   fedale_gridview:
     gridviews:
       user:                    # the grid id (entity short name, lowercased)
         options:
           realtime:
             enabled: true
             # topicPrefix: "gridview/"   # optional, this is the default
   ```

   Only **CRUD grids publish** (a controller extending `AbstractCrudGridController`).
   A read-only grid can still subscribe, but nothing will publish to its topic.

3. Register the Stimulus controller once (app `assets/bootstrap.js`):

   ```js
   import GridviewMercureController from '.../assets/controllers/gridview-mercure_controller.js';
   app.register('gridview-mercure', GridviewMercureController);
   ```

4. Configure the hub URLs. **`MERCURE_PUBLIC_URL` must be the same host as the app**, or
   Mercure refuses to create the subscription cookie ("different second-level domain").
   The simplest setup is to serve the hub **same-origin** via a reverse-proxy on
   `/.well-known/mercure`:

   ```dotenv
   # app/.env(.local)
   MERCURE_URL=http://mercure/.well-known/mercure                 # internal: app → hub (publish)
   MERCURE_PUBLIC_URL=https://your-app.example.com/.well-known/mercure   # browser → hub (subscribe)
   MERCURE_JWT_SECRET="<change-me>"
   ```

   ```nginx
   # reverse-proxy the hub same-origin (SSE: buffering off, long timeout)
   location /.well-known/mercure {
       resolver 127.0.0.11 valid=10s;
       set $upstream http://mercure;
       proxy_pass $upstream$request_uri;
       proxy_http_version 1.1;
       proxy_set_header Connection "";
       proxy_buffering off;
       proxy_read_timeout 24h;
   }
   ```

## The `gridview-mercure` controller

| Value | Type | Description |
|-------|------|-------------|
| `hub` | `String` | Public hub URL (from `MERCURE_PUBLIC_URL`) |
| `topic` | `String` | The grid's topic, e.g. `gridview/user` |
| `form` | `String` | Id of the filter form to re-submit on a change (`gv-form-<key>`) |
| `delay` | `Number` | Debounce window in ms (default `400`) — coalesces bursts / bulk ops |

It opens the `EventSource`, debounces incoming signals, re-submits the grid's filter form
(reusing `gridview-filter`'s loading overlay; falls back to `turbo-frame.reload()`), and
shows a `.gv-info-banner` toast. On disconnect it closes the stream.

> The editor also receives the signal and sees the toast — harmless, their grid is already
> fresh from their own action. To suppress it, include a client id in the payload and
> ignore your own — not done by default.
