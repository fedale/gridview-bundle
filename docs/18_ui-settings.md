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
honour is skipped for that grid. For example, a global "cards" default does nothing on a
grid whose `renderer.map` has no `card` entry.

## Enabling it

The feature is off until the app provides a store, a service implementing
`Fedale\GridviewBundle\UiSettings\UiSettingsStoreInterface`:

```php
interface UiSettingsStoreInterface
{
    public function load(string $scope): array;              // setting key => value
    public function save(string $scope, array $values): void; // replaces the bag
}
```

A scope is `_global` or a grid id. A missing key means "inherit". Point the bundle at the
store:

```yaml
# config/packages/gridview.yaml
fedale_gridview:
    ui_settings:
        store: App\Gridview\SettingBundleUiSettingsStore
```

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

## Storing in the database with fedale/setting-bundle

```php
use Fedale\GridviewBundle\UiSettings\UiSettingsStoreInterface;
use Fedale\SettingBundle\Contract\SettingsManagerInterface;

final class SettingBundleUiSettingsStore implements UiSettingsStoreInterface
{
    public function __construct(private readonly SettingsManagerInterface $settings) {}

    public function load(string $scope): array
    {
        $values = $this->settings->get('gridview.ui.' . $scope, []);

        return \is_array($values) ? $values : [];
    }

    public function save(string $scope, array $values): void
    {
        $this->settings->set('gridview.ui.' . $scope, $values, null, 'json');
    }
}
```

## Adding a setting

Implement `UiSettingInterface`, or extend `AbstractUiSetting`. Autoconfiguration adds the
`fedale_gridview.ui_setting` tag, and the field appears in the modal:

```php
use Fedale\GridviewBundle\UiSettings\AbstractUiSetting;
use Fedale\GridviewBundle\UiSettings\GridDescriptor;

final class EmptyTextUiSetting extends AbstractUiSetting
{
    public function key(): string        { return 'emptyText'; }
    public function optionPath(): string { return 'display.emptyText'; }
    public function label(): string      { return 'ui_settings.empty_text.label'; }

    public function choices(?GridDescriptor $grid): array
    {
        return ['ui_settings.empty_text.short' => 'Nothing here', 'ui_settings.empty_text.long' => 'No records match your filters'];
    }
}
```

| Method | Purpose |
|---|---|
| `key()` | storage key, unique |
| `optionPath()` | dotted path of the grid option it writes |
| `label()` / `help()` | `GridviewBundle` translation keys |
| `supportsGlobal()` / `supportsGrid($grid)` | which scopes offer the field |
| `choices($grid)` | `label key => value`, narrowed per grid when needed |
| `isApplicable($value, $options)` | render-time guard against values a grid can't honour |

The modal renders every setting as an optional choice field. The empty choice means
"inherit": from the code configuration in the global scope, from the global value in a grid
scope.
