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

    protected function viewConfig(): array
    {
        return [
            // The default 'gridview/with_sidebar.html.twig' expects a template
            // supplied by the host app; fall back to the bundle's own bare
            // layout so this controller renders out of the box. Swap this for
            // an app template once one exists.
            'template' => ['index' => '@FedaleGridview/gridview/index.html.twig'],
        ];
    }

    protected function dataConfig(): array
    {
        return [
            'model' => <?= $entity_class_name; ?>::class,
            'alias' => '<?= $alias; ?>',
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
