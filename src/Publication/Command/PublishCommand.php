<?php

declare(strict_types=1);

namespace App\Publication\Command;

use App\Publication\PublicationService;
use App\Publication\PublisherRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Publishes a stored analysis. Required answers that are not given with --option are asked interactively. Formats that
 * write to Confluence or Jira run a dry run first, show it, and publish only after a confirmation.
 */
#[AsCommand(name: 'app:ta:publish', description: 'Publish a stored analysis in one format')]
final class PublishCommand extends Command
{
    /** formats that change things outside the project: always confirmed */
    private const REMOTE = ['confluence', 'agir'];

    public function __construct(
        private readonly PublicationService $service,
        private readonly PublisherRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('document', InputArgument::REQUIRED, 'Document id, as YYYYMMDD_slug')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: '.implode(', ', array_keys($this->registry->all())), 'markdown')
            ->addOption('option', 'o', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Answer to a format question, as name=value')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written, write nothing')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Confirm remote publications without asking');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = (string) $input->getOption('format');
        $documentId = (string) $input->getArgument('document');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $answers = $this->answers((array) $input->getOption('option'));
            foreach ($this->service->optionsFor($format) as $option) {
                if ($option->required && ($answers[$option->name] ?? '') === '' && $option->default !== null) {
                    $answers[$option->name] = $option->default;
                }
                if ($option->required && ($answers[$option->name] ?? '') === '') {
                    if (!$input->isInteractive()) {
                        throw new \InvalidArgumentException(sprintf('Missing option "%s" (use --option %s=...).', $option->name, $option->name));
                    }
                    $answers[$option->name] = (string) $io->askQuestion(new Question(sprintf('%s: ', $option->label)));
                }
            }

            $preview = $this->service->publish($documentId, $format, $answers, true);
            $io->section(sprintf('%s: dry run', $format));
            foreach ($preview->messages as $line) {
                $io->writeln('  '.$line);
            }
            if ($dryRun || $preview->status === 'failed') {
                return $preview->status === 'failed' ? Command::FAILURE : Command::SUCCESS;
            }

            $remote = in_array($format, self::REMOTE, true);
            if ($remote && !$input->getOption('yes') && (!$input->isInteractive() || !$io->confirm('Publish now? Nothing was written so far.', false))) {
                $io->warning('Not published. Re-run with --yes to publish without asking.');

                return Command::FAILURE;
            }

            $result = $this->service->publish($documentId, $format, $answers, false);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->section(sprintf('%s: result', $format));
        foreach ($result->messages as $line) {
            $io->writeln('  '.$line);
        }
        $io->success(sprintf('%s: %s', $format, $result->status));

        return $result->status === 'failed' ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<string> $pairs name=value
     *
     * @return array<string, string>
     */
    private function answers(array $pairs): array
    {
        $answers = [];
        foreach ($pairs as $pair) {
            if (!str_contains($pair, '=')) {
                throw new \InvalidArgumentException(sprintf('Option "%s" must be name=value.', $pair));
            }
            [$name, $value] = explode('=', $pair, 2);
            $answers[$name] = $value;
        }

        return $answers;
    }
}
