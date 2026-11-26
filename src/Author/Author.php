<?php

declare(strict_types=1);

namespace App\Author;

/**
 * One author of the list. A document keeps a copy of it (`author: {name, username, email}`) when it is chosen.
 */
final class Author
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $username = '',
    ) {
    }

    /**
     * @return array{name: string, username?: string, email: string}
     */
    public function toArray(): array
    {
        $out = ['name' => $this->name];
        if ($this->username !== '') {
            $out['username'] = $this->username;
        }
        $out['email'] = $this->email;

        return $out;
    }
}
