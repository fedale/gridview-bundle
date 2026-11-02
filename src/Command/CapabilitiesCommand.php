<?php

namespace Fedale\GridviewBundle\Command;

use Fedale\GridviewBundle\Capabilities\GridCapabilities;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints {@see GridCapabilities::describe()} as JSON, whole or one section of
 * it, for tools that generate grid configuration (and for a quick look at what
 * the application has registered).
 */
#[AsCommand(name: 'gridview:capabilities', description: 'Print what grids can be configured with (types, formats, themes, layout tokens, options schema) as JSON')]
class CapabilitiesCommand extends Command
{
    public function __construct(private readonly GridCapabilities $capabilities)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('section', InputArgument::OPTIONAL, 'Print only this section (e.g. columnTypes, layout, optionsSchema)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = $this->capabilities->describe();

        $section = $input->getArgument('section');
        if (\is_string($section)) {
            if (!\array_key_exists($section, $data)) {
                $output->writeln(sprintf(
                    '<error>Unknown section "%s". Available sections: %s.</error>',
                    $section,
                    implode(', ', array_keys($data)),
                ));

                return Command::INVALID;
            }
            $data = $data[$section];
        }

        $output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
