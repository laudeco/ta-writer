<?php

declare(strict_types=1);

namespace App\Author\Command;

use App\Author\Author;
use App\Author\AuthorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds an author to the list. With --if-empty, nothing happens when the list already has an author: the install script
 * can run again without adding a duplicate.
 */
#[AsCommand(name: 'app:author:add', description: 'Add an author to the list')]
final class AuthorAddCommand extends Command
{
    public function __construct(private readonly AuthorRepository $authors)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Full name')
            ->addArgument('email', InputArgument::REQUIRED, 'Email, unique in the list')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'Login name (optional)', '')
            ->addOption('if-empty', null, InputOption::VALUE_NONE, 'Add only when the list has no author yet');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('if-empty')) {
            $count = count($this->authors->all());
            if ($count > 0) {
                $output->writeln(sprintf('The list already has %d author(s): nothing added.', $count));

                return Command::SUCCESS;
            }
        }

        try {
            $this->authors->add(new Author(
                trim((string) $input->getArgument('name')),
                trim((string) $input->getArgument('email')),
                trim((string) $input->getOption('username')),
            ));
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('Author added: %s <%s>', $input->getArgument('name'), $input->getArgument('email')));

        return Command::SUCCESS;
    }
}
