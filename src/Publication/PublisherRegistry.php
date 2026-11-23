<?php

declare(strict_types=1);

namespace App\Publication;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class PublisherRegistry
{
    /** @var array<string, PublisherInterface> */
    private array $publishers = [];

    /**
     * @param iterable<PublisherInterface> $publishers
     */
    public function __construct(#[AutowireIterator('app.publisher')] iterable $publishers)
    {
        foreach ($publishers as $publisher) {
            $this->publishers[$publisher->getName()] = $publisher;
        }
        ksort($this->publishers);
    }

    public function get(string $name): PublisherInterface
    {
        return $this->publishers[$name]
            ?? throw new \InvalidArgumentException(sprintf('Unknown format "%s". Available: %s.', $name, implode(', ', array_keys($this->publishers))));
    }

    /**
     * @return array<string, PublisherInterface>
     */
    public function all(): array
    {
        return $this->publishers;
    }
}
