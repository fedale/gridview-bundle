# fedale/gridview-bundle

A Gridview component for the Symfony framework, inspired by Yii 2 GridView.
Main inspirations:
- https://www.yiiframework.com/doc/api/2.0/yii-grid-gridview
- https://github.com/kartik-v/yii2-grid
- https://github.com/AlexyAV/AVGridBundle
- https://github.com/APY/APYDataGridBundle
- https://github.com/tinustester/symfony-gridview-bundle

## Installation

```bash
composer require fedale/gridview-bundle
```

Requires PHP >= 8.1 and Symfony >= 6.4. The bundle is auto-registered via Symfony
Flex; otherwise add `Fedale\GridviewBundle\FedaleGridviewBundle` to `config/bundles.php`.

## Usage

First of all, this gridview is not automagic: if you are searching something magical like EasyAdmin gridview this project is not for you. You have, at least, configure a Fedale\GridviewBundle\DataProviderInterface and an array of columns to display.

With an Entity having these properties:
- id
- code
- username 
you can dsplay a grid having these columns configuring this array:

$columns = [
    'id',
    'code',
    'username'
];

$dataProvider = [
    // 'queryBuilder' => $queryBuilder,
    'model' => \App\Entity\Customer\Customer::class,
];

$gridview = $this->createGridviewBuilder()
->setDataProvider($dataProvider)
->setColumns($columns)
->renderGridview();

return $gridview->renderGrid('@FedaleGridview/gridview/index.html.twig', []);

For each you can set further configurations, passing each of them as array. In that case you can configure 'attribute', 'filter' (i.e. a query filter, that it needs a searchModel first), 'value' an anonymous function to return specific value, 'twigFilter' that will be applied to value of cell, 'visibile' 'boolean', 'label' (header of the column).

Try to change array $columns in this way:
$columns = [
    'id',
    [
        'attribute' => 'code',
        'value' => function (array $data, string $key, ColumnInterface $column) {
            return '<strong>' . $data['code'] . '</strong>';
        },
        'twigFilter' => 'raw'
    ],
    [
        'attribute' =>'username',
        'twigFilter' => 'reverse'
    ]    
];

In $dataProvider you can also set 'pagination' and 'sort' parameters.

The 'sort' option is a sub-array: 'map' holds the sortable attribute
definitions, 'default' the initial order and 'multiSort' toggles multi-column
sorting.

// be careful that the map keys must have the same name as columns
$sortAttributes = [
    'id' => [
        'asc' => ['c.id' => Sort::ASC],
        'desc' => ['c.id' => Sort::DESC],
        'default' => Sort::DESC,
    ],
    'code' => [
        'asc' => ['c.code' => Sort::ASC],
        'desc' => ['c.code' => Sort::DESC],
        'default' => Sort::DESC,
    ],
];

$sort = [
    'map' => $sortAttributes,
    // 'multiSort' => true,
    // 'default'   => ['code' => 'desc'],
];

and Gridview becomes sortable by 'id' and 'code' columns!

The 'pagination' option sets the default page size and, optionally, a list of
selectable page sizes rendered as a footer selector ('pageSizeOptions'):
$paginationAttributes = [
    'defaultPageSize' => 25,
    'pageSizeOptions' => [25, 50, 100],
    // 'maxPageSize'  => 100,
];





But the only thing you have to do is to set a config arrays. 
// Order matters! Try to switch setColumns() / setFilterModel()
        $gridview = $this->createGridviewBuilder()
            ->setSearchModel($this->customerSearchModel)
            ->setDataProvider($dataProvider)
            ->setColumns($columns)
            ->setAttributes([
                'class' => 'table table-dark',
                'row' => [
                    'class' => 'row-class'
                ],
                'header' => [
                    'class' => 'row-header'
                ],
                'container' => [
                    'class' => 'row-container',
                    'data-type' => 'my-custom-type'
                ]
            ])
            ->renderGridview();
        ;

where $this->customerSearchModel is a child of Fedale\GridviewBundle\Service\SearchModel
$dataProvider represents a way and how to get data from a source like a database
$dataProvider = [
            // 'queryBuilder' => $queryBuilder,
            'model' => \App\Entity\Customer\Customer::class,
            'pagination' => $paginationAttributes,
            'sort' => $sort
        ];

Let's try with one entity.


How to define relations between entitites?

->setColumns($columns)  is the place where you set columns from different entities. $columns is an array where each item has these keys: 
attribute: the name of columnn
value: is the value to display, you can use a closure 
filter: filter to use, like 'text' or 'select'
twigFilter: one of twig filter 
active: boolean | array | closure — where the column is rendered. `true` (default) everywhere; `false` nowhere (access-control kill-switch: no cell, filter, export or form field); an array gives per-context control with `{inIndex, inView, inCreate, inUpdate}` (omitted keys default to `true`). A column inactive in `index` only is still registered — filterable, exportable and editable in Create/Update forms — but produces no table cell and no "Columns" toggle entry. A closure may return any of these. See docs for `active` vs `visible`
visible: boolean — show/hide a registered column (CSS only; stays in DOM/data)
label: 

### Rendering a cell from a Twig template

A `value` (or `renderer`) closure receives the column as its last argument, and
the column exposes `renderTemplate(string $name, array $context = []): string` —
the grid's own Twig environment, so a cell can be rendered from a template
instead of building HTML inline. Wrap the result in a `Twig\Markup` so it is not
re-escaped:

```php
use Fedale\GridviewBundle\Column\DataColumn;
use Twig\Markup;

[
    'attribute' => 'postCount',
    'label' => 'tag.posts',
    'value' => fn(array $data, int $index, DataColumn $column): Markup => new Markup(
        $column->renderTemplate('gridview/tag/_posts_popularity.html.twig', [
            'count' => (int) ($data['postCount'] ?? 0),
            'published' => (int) ($data['publishedCount'] ?? 0),
        ]),
        'UTF-8'
    ),
],
```

This keeps the cell markup in a template and avoids injecting Twig into the
controller.

## Internationalization (i18n)

The grid supports **instant, client-side language switching** — changing language
rewrites every label in place with no server roundtrip and no page reload.

Translations stay in Symfony YAML (the single source of truth); the bundle ships
the full catalog of every enabled locale to the browser and a small headless
runtime (`window.GridviewI18n`) swaps the text. Two domains are used:
`GridviewBundle` for the built-in chrome and a configurable `Gridview` domain for
your column labels.

Any existing language switcher can drive the grid — via a DOM event
(`gridview:set-locale`), the `window.GridviewI18n.setLocale()` API, or by
observing `<html lang>` — so you never need two switchers. A built-in switcher is
available too (opt-in).

```yaml
# config/packages/gridview.yaml
fedale_gridview:
    i18n:
        locales: [en, it]
        default: en
        client_domain: Gridview
```

See [Internationalization (i18n)](docs/i18n.md)
for the full guide (external switchers, server-side persistence, tagging your own
templates, dynamic JS strings).
