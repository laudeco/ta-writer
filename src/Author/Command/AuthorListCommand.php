<?php

declare(strict_types=1);

namespace App\Author\Command;

use App\Author\AuthorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:author:list', description: 'List the authors')]
final class AuthorListCommand extends Command
{
    public function __construct(private readonly AuthorRepository $authors)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $authors = $this->authors->all();
        if ($authors === []) {
            $output->writeln('No author yet. Add one with: make console c="app:author:add \"Name\" name@example.com"');

            return Command::SUCCESS;
        }
        foreach ($authors as $i => $author) {
            $output->writeln(sprintf('%s %s <%s>%s', $i === 0 ? '*' : ' ', $author->name, $author->email, $author->username !== '' ? ' ('.$author->username.')' : ''));
        }
        $output->writeln('* the first one is the default of a new analysis');

        return Command::SUCCESS;
    }
}
