# UI settings — let end users configure the grids

> ← Back to the [main documentation](index.md) · related: [Configuration](11_configuration.md), [JavaScript](12_javascript.md)

The UI settings modal lets the end user change grid options at runtime, without touching
code or YAML:

- **global** values apply to every grid;
- **per-grid** values override the global ones for a single grid.

The bundle ships one setting, the **default renderer** (table / cards / list). New settings
are one PHP class each (see [Adding a setting](#adding-a-setting)).

## Precedence

From lowest to highest:

1. bundle defaults and YAML (`defaults`, `gridviews.<id>`)
2. the controller's `viewConfig()`
3. the global UI setting
4. the per-grid UI setting
5. request-time choices, e.g. `?view=` from the view switcher

Only the option the setting targets is replaced (e.g. `display.renderer.default`); the rest
of the configuration, such as the renderer `map`, is untouched. A stored value a grid cannot
honour is skipped for that grid, and the next value in line applies. For example, a global
"cards" default does nothing on a grid whose `renderer.map` has no `card` entry.

In a multi-tenant app the values of the current tenant come first. Tenant 0 holds the
platform defaults, which apply only where the tenant has set nothing:

```
tenant's grid value → tenant's global value → platform grid value → platform global value
```

A tenant that sets "cards" for all grids therefore sees cards on every grid that supports
them, even where the platform set a per-grid default.

## Enabling it

The values are stored and resolved by
[fedale/setting-bundle](https://github.com/fedale/setting-bundle) (scoped settings), an
optional dependency. Without it, the modal's route answers 404 and grids render exactly as
configured in code. Install it (PHP 8.2+):

```bash
composer require fedale/setting-bundle
```

Then give its scoped settings a store, for example the built-in Doctrine table:

```php
// config/packages/fedale_setting.php
use Symfony\Config\FedaleSettingConfig;

return static function (FedaleSettingConfig $config): void {
    $config->scoped()->store('doctrine');
};
```

```yaml
# config/packages/fedale_setting.yaml
fedale_setting:
    scoped:
        store: doctrine
```

Generate the migration for its `setting_scoped` table with
`php bin/console make:migration`. The values live in the `gridview.ui` namespace, at the
scope levels `global` and `grid` (the scope id is the grid id). See the setting-bundle README
for custom stores and caching.

Import the route that serves the modal body:

```yaml
# config/routes/gridview_ui_settings.yaml
gridview_ui_settings:
    resource: '@FedaleGridviewBundle/src/Controller/UiSettingsController.php'
    type: attribute
    prefix: /gridview/_settings
```

Then place a trigger and the modal inside one element carrying the `gridview-ui-settings`
Stimulus controller. Typically this is the page header, so it works on every page:

```twig
<div data-controller="gridview-ui-settings"
     data-gridview-ui-settings-url-value="{{ path('fedale_gridview_ui_settings') }}"
     data-gridview-ui-settings-grid-value="{{ gridview.id|default('') }}">
    <button type="button" data-action="gridview-ui-settings#open">
        {{ 'ui_settings.menu'|trans({}, 'GridviewBundle') }}
    </button>

    {% include '@FedaleGridview/ui_settings/_modal.html.twig' %}
</div>
```

The `grid` value preselects the current page's grid; leave it empty for the global scope.

## How it works (Hotwire)

- The modal body is a `<turbo-frame id="gv-ui-settings">`, loaded when the modal opens.
- Switching the scope submits a GET form inside the frame; Turbo navigates only the frame.
- Saving POSTs the form and redirects back with `saved=1` (Post/Redirect/Get). When the
  "saved" marker arrives, the controller reloads every `turbo-frame[id^="gridview-"]` on the
  page from the current URL. The grids re-render with the new settings without a full page
  reload.
- Grids are discovered automatically: every `AbstractGridController` service becomes a scope,
  described by `describeGrid()` from its `viewConfig()` alone.

## Adding a setting

Extend `AbstractUiSetting` (or implement `UiSettingInterface`). It is a setting-bundle
scoped setting, so setting-bundle's autoconfiguration registers it, and the field appears in
the modal:

```php
use Fedale\GridviewBundle\UiSettings\AbstractUiSetting;
use Fedale\GridviewBundle\UiSettings\GridDescriptor;

final class EmptyTextUiSetting extends AbstractUiSetting
{
    public function key(): string        { return 'emptyText'; }
    public function optionPath(): string { return 'display.emptyText'; }
    public function label(): string      { return 'ui_settings.empty_text.label'; }

    public function choicesFor(?GridDescriptor $grid): array
    {
        return ['ui_settings.empty_text.short' => 'Nothing here', 'ui_settings.empty_text.long' => 'No records match your filters'];
    }
}
```

| Method | Purpose |
|---|---|
| `key()` | storage key, unique within the `gridview.ui` namespace |
| `optionPath()` | dotted path of the grid option it writes |
| `label()` / `help()` | `GridviewBundle` translation keys |
| `supportsGlobal()` / `supportsGrid($grid)` | which scopes offer the field |
| `choicesFor($grid)` | `label key => value`, narrowed per grid when needed |
| `isApplicable($value, $options)` | render-time guard against values a grid can't honour; `$options` is the grid's resolved options, or null outside a grid |

`AbstractUiSetting` also answers setting-bundle's own questions from these: `namespace()`
is `gridview.ui`, `type()` is an enum, `supportsScope()` follows `supportsGlobal()`, and the
global choices are validated on save. Override `constraints()` to add Symfony Validator
constraints.

The modal renders every setting as an optional choice field. The empty choice means
"inherit", and it names the value inherited: from the global scope or the platform defaults,
else the code configuration.
