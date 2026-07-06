<?php

namespace Fedale\GridviewBundle\Maker;

use Fedale\GridviewBundle\Maker\Util\PhpArrayPrinter;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Doctrine\DoctrineHelper;
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Symfony\Bundle\MakerBundle\FileManager;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Bundle\MakerBundle\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Inflector\EnglishInflector;

/**
 * `make:gridview:crud`: scaffolds a full CRUD gridview controller for a Doctrine
 * entity, deriving the column/filter/control/sort configuration from the
 * entity's metadata via {@see DoctrineTypeMapper}.
 */
final class MakeGridCrud extends AbstractMaker
{
    /** @var array<string, array{label?: string, sortable?: bool, filter?: bool, control?: bool}> */
    private array $advancedOverrides = [];

    public function __construct(
        private readonly DoctrineHelper $doctrineHelper,
        private readonly Generator $generator,
        private readonly FileManager $fileManager,
    ) {
    }

    public static function getCommandName(): string
    {
        return 'make:gridview:crud';
    }

    public static function getCommandDescription(): string
    {
        return 'Create a full CRUD gridview controller for a Doctrine entity';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->addArgument('entity-class', InputArgument::OPTIONAL, \sprintf(
                'The class name of the entity to build the grid for (e.g. <fg=yellow>%s</>)',
                Str::asClassName(Str::getRandomTerm()),
            ))
            ->addOption('controller-class', null, InputOption::VALUE_OPTIONAL, 'Fully-qualified or short controller class name')
            ->addOption('route-prefix', null, InputOption::VALUE_OPTIONAL, 'Route path prefix, e.g. /gridview/category')
            ->addOption('fields', null, InputOption::VALUE_OPTIONAL, 'Comma-separated entity fields/associations to expose as columns (default: all eligible)')
            ->addOption('sort', null, InputOption::VALUE_OPTIONAL, 'Default sort field; prefix with "-" for descending (default: identifier, descending)')
            ->addOption('page-size', null, InputOption::VALUE_OPTIONAL, 'Default page size (default: 20)')
        ;

        // We drive the entity-class prompt ourselves (with autocomplete), same as make:crud.
        $inputConfig->setArgumentAsNonInteractive('entity-class');
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
        $dependencies->addClassDependency(AbstractType::class, 'form');
        $dependencies->addClassDependency(Route::class, 'router');
    }

    public function interact(InputInterface $input, ConsoleStyle $io, Command $command): void
    {
        if ($input->getArgument('entity-class') === null) {
            $argument = $command->getDefinition()->getArgument('entity-class');
            $entities = $this->doctrineHelper->getEntitiesForAutocomplete();

            $question = new Question($argument->getDescription());
            $question->setAutocompleterValues($entities);
            $input->setArgument('entity-class', $io->askQuestion($question));
        }

        $entityClassName = Validator::entityExists($input->getArgument('entity-class'), $this->doctrineHelper->getEntitiesForAutocomplete());
        $metadata = $this->doctrineHelper->getMetadata($this->resolveEntityFqcn($entityClassName));
        $shortName = Str::getShortClassName($entityClassName);

        $controllerClass = $input->getOption('controller-class');
        if ($controllerClass === null) {
            // Reject a name whose target file already exists here, at the prompt,
            // rather than letting the whole wizard run and fail at generation time.
            $default = $this->defaultControllerClassName($shortName);
            do {
                $controllerClass = $io->ask('Controller class name', $default);
                $existingPath = $this->controllerFileIfExists($controllerClass);
                if ($existingPath !== null) {
                    $io->error(\sprintf('The controller file "%s" already exists. Choose a different class name.', $existingPath));
                    $default = null;
                }
            } while ($existingPath !== null);

            $input->setOption('controller-class', $controllerClass);
        } elseif (($existingPath = $this->controllerFileIfExists($controllerClass)) !== null) {
            throw new RuntimeCommandException(\sprintf('The controller file "%s" already exists. Pass a different --controller-class.', $existingPath));
        }

        if ($input->getOption('route-prefix') === null) {
            $input->setOption('route-prefix', $io->ask('Route path prefix', $this->defaultRoutePrefix($shortName)));
        }

        $mapper = new DoctrineTypeMapper();
        $allFields = $mapper->describeFields($metadata);
        $selectable = array_values(array_filter($allFields, static fn(array $f) => !$f['isIdentifier']));

        if ($input->getOption('fields') === null) {
            $choices = array_map(static fn(array $f) => $f['name'], $selectable);
            $default = $mapper->defaultSelection($allFields);

            $selected = [];
            if ($choices !== []) {
                // A plain free-text Question, not $io->choice(): ChoiceQuestion
                // auto-enables its raw-terminal autocompleter, which is unreliable
                // outside a real TTY and can turn a stray PHP warning into an
                // infinite retry loop (QuestionHelper retries forever when
                // maxAttempts is unset). No autocompleter is attached here.
                $question = new Question('Fields to expose as grid columns', implode(',', $default));
                $question->setValidator(function (?string $answer) use ($choices): array {
                    $names = array_filter(array_map('trim', explode(',', (string) $answer)));
                    foreach ($names as $name) {
                        if (!\in_array($name, $choices, true)) {
                            throw new RuntimeCommandException(\sprintf('Unknown field "%s".', $name));
                        }
                    }

                    return $names;
                });
                $question->setMaxAttempts(3);
                $io->writeln(\sprintf('Available fields: %s', implode(', ', $choices)));
                $selected = $io->askQuestion($question);
            }
            $input->setOption('fields', implode(',', $selected));
        }

        if ($io->confirm('Customize labels/sort/filter/form field per column?', false)) {
            $selectedNames = array_filter(array_map('trim', explode(',', (string) $input->getOption('fields'))));
            foreach ($selectedNames as $name) {
                $io->section($name);
                $this->advancedOverrides[$name] = [
                    'label' => $io->ask('Label', $mapper->humanLabel($name)),
                    'sortable' => $io->confirm('Sortable?', true),
                    'filter' => $io->confirm('Filterable?', true),
                    'control' => $io->confirm('Editable in the add/edit form?', true),
                ];
            }
        }

        if ($input->getOption('sort') === null) {
            $identifier = $metadata->getIdentifierFieldNames()[0] ?? 'id';
            $input->setOption('sort', $io->ask('Default sort field (prefix with "-" for descending)', '-' . $identifier));
        }

        if ($input->getOption('page-size') === null) {
            $input->setOption('page-size', $io->ask('Default page size', '20'));
        }
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $entityClassName = Validator::entityExists($input->getArgument('entity-class'), $this->doctrineHelper->getEntitiesForAutocomplete());
        $entityClassDetails = $generator->createClassNameDetails($entityClassName, 'Entity\\');
        $metadata = $this->doctrineHelper->getMetadata($entityClassDetails->getFullName());
        $shortName = $entityClassDetails->getShortName();

        $controllerName = $input->getOption('controller-class') ?? $this->defaultControllerClassName($shortName);
        $routePrefix = $input->getOption('route-prefix') ?? $this->defaultRoutePrefix($shortName);
        $routeNamePrefix = 'gridview_' . Str::asSnakeCase($shortName) . '_';
        $pageSize = (int) ($input->getOption('page-size') ?? 20);
        $alias = 'e';

        $mapper = new DoctrineTypeMapper();
        $allFields = $mapper->describeFields($metadata);

        $fieldsOption = $input->getOption('fields');
        $selectedNames = $fieldsOption !== null && $fieldsOption !== ''
            ? array_filter(array_map('trim', explode(',', $fieldsOption)))
            : $mapper->defaultSelection($allFields);

        if ($selectedNames === []) {
            throw new RuntimeCommandException('No fields selected for the grid. Pass --fields=name,description or answer the interactive prompt.');
        }

        $identifiers = $metadata->getIdentifierFieldNames();
        $orderedNames = array_values(array_unique(array_merge($identifiers, $selectedNames)));
        $selectedFields = array_values(array_filter($allFields, static fn(array $f) => \in_array($f['name'], $orderedNames, true)));

        $plans = $mapper->buildColumnPlans($selectedFields, $this->doctrineHelper, $metadata, $this->advancedOverrides);

        $sortOption = $input->getOption('sort') ?? ('-' . ($identifiers[0] ?? 'id'));
        [$sortField, $sortDirection] = $this->parseSortOption($sortOption);

        $controllerClassDetails = $generator->createClassNameDetails($controllerName, 'Controller\\Gridview\\', 'Controller');

        $searchFields = $mapper->searchFieldsFor($plans, $alias);

        $variables = [
            // 'namespace' and 'class_name' are auto-derived and injected by
            // Generator::generateClass() itself from the target class name.
            'entity_full_class_name' => $entityClassDetails->getFullName(),
            'entity_class_name' => $shortName,
            'route_prefix' => $routePrefix,
            'route_name_prefix' => $routeNamePrefix,
            'alias' => $alias,
            'page_size' => $pageSize,
            'sort_map_php' => PhpArrayPrinter::export($mapper->sortMapFor($plans, $alias), 4),
            'sort_default_php' => \sprintf("['%s' => '%s']", $sortField, $sortDirection),
            'columns_php' => PhpArrayPrinter::export($mapper->columnsArrayFor($plans), 2),
            'search_fields_php' => $searchFields !== [] ? PhpArrayPrinter::export($searchFields, 3) : null,
        ];

        $generator->generateController(
            $controllerClassDetails->getFullName(),
            __DIR__ . '/Resources/skeleton/crud/Controller.tpl.php',
            $variables,
        );

        $generator->writeChanges();
        $this->writeSuccessMessage($io);

        $io->text([
            \sprintf('Next: browse to <fg=yellow>%s</> (route auto-discovered from your app\'s existing attribute-routing config).', $routePrefix),
        ]);
    }

    /** Resolves the (possibly relative-to-entity-namespace) autocomplete value to a FQCN for {@see DoctrineHelper::getMetadata()}. */
    private function resolveEntityFqcn(string $entityClassName): string
    {
        if (str_starts_with($entityClassName, '\\')) {
            return ltrim($entityClassName, '\\');
        }

        return rtrim($this->doctrineHelper->getEntityNamespace(), '\\') . '\\' . $entityClassName;
    }

    /**
     * The relative path of the controller that {@see generate()} would write for
     * this class name, but only when that file already exists; null otherwise.
     * Resolution mirrors the {@see Generator::createClassNameDetails()} call in
     * {@see generate()}, so the check matches what generation would produce.
     */
    private function controllerFileIfExists(string $controllerName): ?string
    {
        $details = $this->generator->createClassNameDetails($controllerName, 'Controller\\Gridview\\', 'Controller');
        $path = $this->fileManager->getRelativePathForFutureClass($details->getFullName());

        return $path !== null && $this->fileManager->fileExists($path) ? $path : null;
    }

    private function defaultControllerClassName(string $entityShortName): string
    {
        return $entityShortName . 'Controller';
    }

    private function defaultRoutePrefix(string $entityShortName): string
    {
        // Pluralize the path segment (Sylius parity: '/admin/suppliers'), while
        // the route-name prefix stays singular ('gridview_supplier_'). The route
        // name uses '_' word boundaries, the path a single lowercased word.
        $segment = str_replace('_', '', Str::asSnakeCase($entityShortName));
        $plural = (new EnglishInflector())->pluralize($segment);

        return '/gridview/' . ($plural[0] ?? $segment);
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    private function parseSortOption(string $sort): array
    {
        if (str_starts_with($sort, '-')) {
            return [substr($sort, 1), 'desc'];
        }

        return [$sort, 'asc'];
    }
}
