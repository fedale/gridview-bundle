<?= "<?php\n" ?>

namespace <?= $namespace; ?>;

use <?= $entity_full_class_name; ?>;
use Fedale\GridviewBundle\Controller\AbstractCrudGridController;
use Symfony\Component\Routing\Attribute\Route;

#[Route('<?= $route_prefix; ?>', name: '<?= $route_name_prefix; ?>')]
class <?= $class_name; ?> extends AbstractCrudGridController
{
    protected function getDataClass(): string
    {
        return <?= $entity_class_name; ?>::class;
    }

    // Uncomment any part to customize the view. Each key below shows its
    // default; keep only the ones you actually override. Without an override the
    // grid uses 'gridview/with_sidebar.html.twig', which expects a layout
    // template from your app: point 'template.index' at one of yours, or at the
    // bundle's bare '@FedaleGridview/gridview/index.html.twig' to render out of
    // the box.
    // protected function viewConfig(): array
    // {
    //     return [
    //         // Page/modal titles; null derives them from the grid id (e.g. '<id>.label').
    //         'labels' => ['heading' => null, 'add' => null, 'edit' => null],
    //         // Grid <table> wrapper attributes.
    //         'attributes' => ['class' => 'table'],
    //         // Export menu: filename (null = grid id) and formats (null = all registered).
    //         'export' => ['filename' => null, 'formats' => null],
    //         'template' => [
    //             // List page layout; '@FedaleGridview/gridview/index.html.twig' renders standalone.
    //             'index' => 'gridview/with_sidebar.html.twig',
    //             // Full-page wrapper for the 'page'/'custom' form modes; null = bundle default.
    //             'page' => null,
    //         ],
    //         'form' => [
    //             // How the add/edit form is shown: 'modal' | 'page' | 'custom' (null = built-in default).
    //             'mode' => null,
    //             // Symfony form theme(s), e.g. ['bootstrap_5_layout.html.twig'].
    //             'theme' => '@FedaleGridview/form/gv_form_theme.html.twig',
    //             // Custom form layout template; null = automatic rendering.
    //             'view' => null,
    //             // Action buttons: 'header' placement drops the in-form submit; 'layout' orders 'buttons'.
    //             'actions' => ['placement' => 'inline', 'layout' => null, 'buttons' => null],
    //             // Query key of the filter form (used to resolve "all" bulk ids).
    //             'filterName' => 'fedaleForm',
    //         ],
    //         // display / behavior / integration overrides, plus 'actionLayout' for the action column.
    //         'options' => [],
    //     ];
    // }

    protected function dataConfig(): array
    {
        return [
            'model' => <?= $entity_class_name; ?>::class,
<?php if ($alias !== null): ?>
            'alias' => '<?= $alias; ?>',
<?php else: ?>
            // 'alias' defaults to 'e' (matching the DQL used in 'searchFields'
            // and 'sort' below). Set it only to change the query-builder alias.
<?php endif; ?>
            'pagination' => ['defaultPageSize' => <?= $page_size; ?>],
<?php if ($search_fields_php !== null): ?>
            'searchFields' => <?= $search_fields_php; ?>,
<?php endif; ?>
            'sort' => [
                'map' => <?= $sort_map_php; ?>,
                'default' => <?= $sort_default_php; ?>,
            ],
        ];
    }

    /** @return array<int, mixed> */
    protected function buildColumns(): array
    {
        return <?= $columns_php; ?>;
    }
}
