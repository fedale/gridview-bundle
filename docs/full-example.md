# Full Example

A complete controller action combining the most common features:

```php
use Fedale\GridviewBundle\Column\ActionButton;

#[Route('/customers', name: 'customer_list', methods: ['GET'])]
public function list(Request $request): Response
{
    $dataProvider = [
        'model'      => Customer::class,
        'pagination' => ['defaultPageSize' => 25],
        'sort'       => [
            'map' => [
                'name'  => ['asc' => ['c.name'],  'desc' => ['c.name'],  'default' => 'asc'],
                'email' => ['asc' => ['c.email'], 'desc' => ['c.email'], 'default' => 'asc'],
            ],
        ],
    ];

    $columns = [
        ['type' => 'checkbox'],
        'id',
        [
            'attribute' => 'name',
            'label'     => 'Full Name',
            'filter'    => ['type' => 'text'],
        ],
        [
            'attribute'  => 'email',
            'label'      => 'E-Mail',
            'value'      => fn(array $data) => '<a href="mailto:' . $data['email'] . '">' . $data['email'] . '</a>',
            'twigFilter' => 'raw',
            'filter'     => ['type' => 'text'],
        ],
        [
            'attribute' => 'internal_notes',
            'label'     => 'Notes',
            'visible'   => false,
        ],
        [
            'type'    => 'action',
            'layout'  => '{view} {edit} {delete}',
            'buttons' => [
                'view' => new ActionButton(
                    fn(array $row) => sprintf('<a href="/customers/%d">View</a>', $row['id'])
                ),
                'edit' => new ActionButton(
                    fn(array $row) => sprintf('<a href="/customers/%d/edit">Edit</a>', $row['id']),
                    roles: ['ROLE_EDITOR', 'ROLE_ADMIN'],
                ),
                'delete' => new ActionButton(
                    fn(array $row) => sprintf('<a href="/customers/%d/delete">Delete</a>', $row['id']),
                    roles: ['ROLE_ADMIN'],
                ),
            ],
        ],
    ];

    $gridview = $this->createGridviewBuilder()
        ->setId('customer_list')                          // uses YAML preset if defined
        ->setSearchModel($this->customerSearchModel)
        ->setOptions([
            'globalSearch' => ['c.name', 'c.email'],
            'addRoute'     => 'customer_new',
            'addLabel'     => 'New Customer',
            'layout'       => [
                'shell'    => '{toolbar} {header} {dataview} {footer}',
                'toolbar'  => '{addButton} {columnVisibility}',
                'header'   => '{globalSearch}',
                'footer'   => '{pagination}',
            ],
        ])
        ->setAttributes([
            'class'     => 'table table-striped',
            'container' => ['class' => 'table-responsive'],
        ])
        ->setDataProvider($dataProvider)
        ->setColumns($columns)
        ->renderGridview();

    return $gridview->renderGrid('@FedaleGridview/gridview/index.html.twig', []);
}
```
