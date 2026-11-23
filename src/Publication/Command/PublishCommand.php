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
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ta:publish', description: 'Publish a stored analysis in one format')]
final class PublishCommand extends Command
{
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = (string) $input->getOption('format');

        try {
            $options = $this->answers((array) $input->getOption('option'));
            $result = $this->service->publish(
                (string) $input->getArgument('document'),
                $format,
                $options,
                (bool) $input->getOption('dry-run'),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

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
