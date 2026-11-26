<?php

declare(strict_types=1);

namespace App\Author;

use App\Storage\AtomicFileWriter;

/**
 * The list of authors, kept in one local JSON file. An author is identified by its email, which is unique in the list.
 * A missing or unreadable file is an empty list: the list is created by the first author added.
 */
final class AuthorRepository
{
    public function __construct(
        private readonly string $path,
        private readonly AtomicFileWriter $writer,
    ) {
    }

    /**
     * @return list<Author>
     */
    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $rows = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($rows)) {
            return [];
        }

        $authors = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['email'] ?? null) && is_string($row['name'] ?? null)) {
                $authors[] = new Author($row['name'], $row['email'], is_string($row['username'] ?? null) ? $row['username'] : '');
            }
        }

        return $authors;
    }

    /**
     * The first author of the list: the default of a new analysis.
     */
    public function first(): ?Author
    {
        return $this->all()[0] ?? null;
    }

    public function find(string $email): ?Author
    {
        foreach ($this->all() as $author) {
            if (strcasecmp($author->email, $email) === 0) {
                return $author;
            }
        }

        return null;
    }

    /**
     * @throws \InvalidArgumentException when the author is invalid or its email is already in the list
     */
    public function add(Author $author): void
    {
        $authors = $this->all();
        $this->assertValid($author, $authors, null);
        $authors[] = $author;
        $this->save($authors);
    }

    /**
     * Replaces the author with this email. The email itself may change, if the new one is free.
     *
     * @throws \InvalidArgumentException when the new values are invalid or the email is taken by another author
     */
    public function update(string $email, Author $author): void
    {
        $authors = $this->all();
        $index = $this->indexOf($authors, $email);
        if ($index === null) {
            throw new \InvalidArgumentException(sprintf('No author with the email %s.', $email));
        }
        $this->assertValid($author, $authors, $index);
        $authors[$index] = $author;
        $this->save($authors);
    }

    public function remove(string $email): bool
    {
        $authors = $this->all();
        $index = $this->indexOf($authors, $email);
        if ($index === null) {
            return false;
        }
        array_splice($authors, $index, 1);
        $this->save($authors);

        return true;
    }

    /**
     * @param list<Author> $authors
     */
    private function indexOf(array $authors, string $email): ?int
    {
        foreach ($authors as $i => $author) {
            if (strcasecmp($author->email, $email) === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<Author> $authors the list as it is, without the author being checked when $ignore is its position
     */
    private function assertValid(Author $author, array $authors, ?int $ignore): void
    {
        if (trim($author->name) === '') {
            throw new \InvalidArgumentException('The name of an author is required.');
        }
        if (filter_var($author->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an email address.', $author->email));
        }
        foreach ($authors as $i => $existing) {
            if ($i !== $ignore && strcasecmp($existing->email, $author->email) === 0) {
                throw new \InvalidArgumentException(sprintf('An author with the email %s is already in the list.', $author->email));
            }
        }
    }

    /**
     * @param list<Author> $authors
     */
    private function save(array $authors): void
    {
        $rows = array_map(static fn (Author $author): array => $author->toArray(), $authors);
        $this->writer->write($this->path, (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
