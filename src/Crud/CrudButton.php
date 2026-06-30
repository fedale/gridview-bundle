<?php

namespace Fedale\GridviewBundle\Crud;

/**
 * Markup factory for CRUD action triggers used inside an ActionColumn. The host
 * app supplies the already-generated route URLs (routes are app-owned); these
 * helpers wrap them with the data-action hooks the gridview-crud Stimulus
 * controller listens to. Keeps app-side button closures one-liners.
 */
final class CrudButton
{
    private const ICON_VIEW   = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    private const ICON_EDIT   = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    private const ICON_CLONE  = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
    private const ICON_DELETE = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc3545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';

    /**
     * Edit trigger. In 'modal' mode opens the CRUD modal (with a real href as a
     * no-JS fallback); in 'page'/'custom' mode it's a plain link to the form page.
     */
    public static function edit(string $url, string $mode = 'modal', string $title = 'crud.edit'): string
    {
        return self::action($url, $mode, $title, self::ICON_EDIT);
    }

    public static function clone(string $url, string $mode = 'modal', string $title = 'crud.clone'): string
    {
        return self::action($url, $mode, $title, self::ICON_CLONE);
    }

    /**
     * Plain navigation link to a record's detail/show page (no modal). The host
     * app owns the `show` route; auto-wired only when such a route exists.
     */
    public static function view(string $url, string $title = 'crud.view'): string
    {
        return sprintf('<a href="%s" %s>%s</a>', self::esc($url), self::titleAttrs($title), self::ICON_VIEW);
    }

    /**
     * Generic navigation link for a custom action button (e.g. a cross-grid
     * jump). A plain <a> sharing the built-in actions' tooltip/i18n conventions:
     * $icon is raw inline markup (SVG or emoji) supplied by the host, and $title
     * is a translation key (GridviewBundle or client domain) the i18n runtime
     * swaps in. The host app owns the target $url.
     */
    public static function link(string $url, string $icon, string $title): string
    {
        return sprintf('<a href="%s" %s>%s</a>', self::esc($url), self::titleAttrs($title), $icon);
    }

    /**
     * Opens the delete-confirmation recap modal. $url is the confirm endpoint
     * (GET) that returns the recap + CSRF form; no token is needed here.
     */
    public static function delete(string $url, string $title = 'crud.delete'): string
    {
        return sprintf(
            '<a href="#" %s data-action="gridview-crud#open" data-gridview-crud-url-param="%s">%s</a>',
            self::titleAttrs($title),
            self::esc($url),
            self::ICON_DELETE
        );
    }

    /**
     * Renders an edit/clone action honoring the CRUD mode: 'modal' opens the
     * dialog (real href as no-JS fallback), otherwise a plain navigation link.
     */
    private static function action(string $url, string $mode, string $title, string $icon): string
    {
        if ($mode === 'modal') {
            return sprintf(
                '<a href="%s" %s data-action="gridview-crud#open" data-gridview-crud-url-param="%s">%s</a>',
                self::esc($url),
                self::titleAttrs($title),
                self::esc($url),
                $icon
            );
        }

        return sprintf('<a href="%s" %s>%s</a>', self::esc($url), self::titleAttrs($title), $icon);
    }

    /**
     * Tooltip attributes. The default titles are translation keys (crud.*) of
     * the GridviewBundle domain, so the i18n runtime swaps them instantly; the
     * raw value is also set on `title` as a no-JS fallback. A free-form string
     * passed by the host simply stays as-is (no catalog entry → JS leaves it).
     */
    private static function titleAttrs(string $title): string
    {
        return sprintf(
            'title="%s" data-gv-i18n-attr-title="%s"',
            self::esc($title),
            self::esc($title)
        );
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES, 'UTF-8');
    }
}
