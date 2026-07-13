<?php

namespace Fedale\GridviewBundle\Maker;

use Fedale\GridviewBundle\Maker\Util\PhpArrayPrinter;
use Fedale\GridviewBundle\Maker\Util\ViewConfigFooterInjector;
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
    /** Built-in footer region layout, mirroring GridviewConfigRegistry's default. */
    private const DEFAULT_FOOTER_LAYOUT = '{resultsSummary} {pagination} {pageSize}';

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
            ->addOption('alias', null, InputOption::VALUE_OPTIONAL, 'Query-builder root alias for the sort/search DQL (default: e)')
            ->addOption('checkbox', null, InputOption::VALUE_NONE, 'Add a row-selection checkbox column')
            ->addOption('advanced', null, InputOption::VALUE_NONE, 'Ask the extra questions skipped by default (per-column labels/sort/filter/form, default sort, page size)')
            // Update mode: passing this switches the maker from scaffolding a new
            // controller to setting the layout footer region of an existing one.
            // Default false = not passed; null = passed bare (prompt/default layout);
            // a string = explicit footer tokens.
            ->addOption('set-footer', null, InputOption::VALUE_OPTIONAL, 'Update mode: set the layout footer region of an EXISTING controller (footer tokens; omit for the bundle default)', false)
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
        if ($this->isFooterMode($input)) {
            $this->interactFooter($input, $io, $command);

            return;
        }

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

        // The per-column, default-sort and page-size prompts are noisy for the
        // common case; they only run under --advanced. Without it, generation
        // falls back to sensible defaults (identifier-descending sort, page size
        // 20, no per-column overrides).
        if ($input->getOption('advanced')) {
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

            // Repositories with a custom search() build the query with their own
            // root alias (e.g. 't'); the generated sort/search DQL must match it.
            // Without search(), the fallback uses whatever alias is set here.
            if ($input->getOption('alias') === null) {
                $input->setOption('alias', $io->ask('Query-builder root alias (used in sort/search DQL)', 'e'));
            }
        }

        // Asked in every mode: the checkbox column is a common opt-in, not an
        // advanced tweak. Skipped only when --checkbox was already passed.
        if (!$input->getOption('checkbox')) {
            $input->setOption('checkbox', $io->confirm('Add a row-selection checkbox column?', false));
        }
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        if ($this->isFooterMode($input)) {
            $this->updateFooter($input, $io);

            return;
        }

        $entityClassName = Validator::entityExists($input->getArgument('entity-class'), $this->doctrineHelper->getEntitiesForAutocomplete());
        $entityClassDetails = $generator->createClassNameDetails($entityClassName, 'Entity\\');
        $metadata = $this->doctrineHelper->getMetadata($entityClassDetails->getFullName());
        $shortName = $entityClassDetails->getShortName();

        $controllerName = $input->getOption('controller-class') ?? $this->defaultControllerClassName($shortName);
        $routePrefix = $input->getOption('route-prefix') ?? $this->defaultRoutePrefix($shortName);
        $routeNamePrefix = 'gridview_' . Str::asSnakeCase($shortName) . '_';
        $pageSize = (int) ($input->getOption('page-size') ?? 20);
        $alias = $input->getOption('alias') ?: 'e';

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
            // Only emitted when it differs from the provider's built-in 'e'.
            'alias' => $alias !== 'e' ? $alias : null,
            'page_size' => $pageSize,
            'sort_map_php' => PhpArrayPrinter::export($mapper->sortMapFor($plans, $alias), 4),
            'sort_default_php' => \sprintf("['%s' => '%s']", $sortField, $sortDirection),
            'columns_php' => PhpArrayPrinter::export($mapper->columnsArrayFor($plans, (bool) $input->getOption('checkbox')), 2),
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

    /** Whether the maker runs in footer-update mode rather than scaffolding a new controller. */
    private function isFooterMode(InputInterface $input): bool
    {
        return $input->getOption('set-footer') !== false;
    }

    /**
     * Footer-mode counterpart of {@see interact()}: resolve the target controller
     * (which must already exist) and the footer layout, skipping every scaffold
     * prompt.
     */
    private function interactFooter(InputInterface $input, ConsoleStyle $io, Command $command): void
    {
        $controllerClass = $input->getOption('controller-class');
        if ($controllerClass === null) {
            if ($input->getArgument('entity-class') === null) {
                $argument = $command->getDefinition()->getArgument('entity-class');
                $question = new Question($argument->getDescription());
                $question->setAutocompleterValues($this->doctrineHelper->getEntitiesForAutocomplete());
                $input->setArgument('entity-class', $io->askQuestion($question));
            }

            $entityClassName = Validator::entityExists($input->getArgument('entity-class'), $this->doctrineHelper->getEntitiesForAutocomplete());
            $controllerClass = $io->ask('Controller class name to update', $this->defaultControllerClassName(Str::getShortClassName($entityClassName)));
            $input->setOption('controller-class', $controllerClass);
        }

        if ($this->controllerFileIfExists($controllerClass) === null) {
            throw new RuntimeCommandException(\sprintf('No controller "%s" found to update. Scaffold it first with make:gridview:crud.', $controllerClass));
        }

        // A bare --set-footer (null) prompts for the tokens; an explicit value skips.
        if ($input->getOption('set-footer') === null) {
            $input->setOption('set-footer', $io->ask('Footer region layout (tokens)', self::DEFAULT_FOOTER_LAYOUT));
        }
    }

    /**
     * Set the layout footer region of an existing controller by adding a
     * `viewConfig()` method via {@see ViewConfigFooterInjector}. When the
     * controller already defines an (active) `viewConfig()`, the nested config is
     * left untouched — rewriting a hand-edited array literal is unsafe — and the
     * snippet to paste is printed instead.
     */
    private function updateFooter(InputInterface $input, ConsoleStyle $io): void
    {
        $controllerClass = $input->getOption('controller-class');
        if ($controllerClass === null) {
            $entityClassName = Validator::entityExists($input->getArgument('entity-class'), $this->doctrineHelper->getEntitiesForAutocomplete());
            $controllerClass = $this->defaultControllerClassName(Str::getShortClassName($entityClassName));
        }

        $path = $this->controllerFileIfExists($controllerClass);
        if ($path === null) {
            throw new RuntimeCommandException(\sprintf('No controller "%s" found to update. Scaffold it first with make:gridview:crud.', $controllerClass));
        }

        $setFooter = $input->getOption('set-footer');
        $layout = ($setFooter === null || $setFooter === '') ? self::DEFAULT_FOOTER_LAYOUT : $setFooter;

        $source = $this->fileManager->getFileContents($path);
        $injector = new ViewConfigFooterInjector();

        if ($injector->hasViewConfig($source)) {
            $io->warning(\sprintf('The controller "%s" already defines viewConfig(); leaving it untouched. Add the footer under options.display.layout:', $controllerClass));
            $io->writeln($injector->snippet($layout));

            return;
        }

        $this->fileManager->dumpFile($path, $injector->addFooter($source, $layout));

        $this->writeSuccessMessage($io);
        $io->text(\sprintf('Set the footer region of <fg=yellow>%s</> to "%s".', $controllerClass, $layout));
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
