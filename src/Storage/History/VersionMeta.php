<?php

declare(strict_types=1);

namespace App\Storage\History;

final class VersionMeta
{
    /**
     * @param list<string> $changedPaths JSON Pointers changed compared with the previous version
     */
    public function __construct(
        public readonly int $number,
        public readonly \DateTimeImmutable $createdAt,
        public readonly string $origin,
        public readonly ?string $author,
        public readonly array $changedPaths,
    ) {
    }

    /**
     * @return array{number: int, createdAt: string, origin: string, author: ?string, changedPaths: list<string>}
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'origin' => $this->origin,
            'author' => $this->author,
            'changedPaths' => $this->changedPaths,
        ];
    }

    /**
     * @param array{number: int, createdAt: string, origin: string, author: ?string, changedPaths: list<string>} $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            $row['number'],
            new \DateTimeImmutable($row['createdAt']),
            $row['origin'],
            $row['author'],
            $row['changedPaths'],
        );
    }
}
