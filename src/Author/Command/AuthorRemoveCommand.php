<?php

declare(strict_types=1);

namespace App\Author\Command;

use App\Author\AuthorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes an author from the list. The analyses that already hold a copy of the author keep it.
 */
#[AsCommand(name: 'app:author:remove', description: 'Remove an author from the list (analyses keep their copy)')]
final class AuthorRemoveCommand extends Command
{
    public function __construct(private readonly AuthorRepository $authors)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email of the author to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        if (!$this->authors->remove($email)) {
            $output->writeln(sprintf('<error>No author with the email %s.</error>', $email));

            return Command::FAILURE;
        }
        $output->writeln(sprintf('Author removed: %s', $email));

        return Command::SUCCESS;
    }
}
