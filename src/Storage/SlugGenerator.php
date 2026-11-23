<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Stable ASCII file name for a document, built from its project reference and name.
 */
final class SlugGenerator
{
    private AsciiSlugger $slugger;

    public function __construct()
    {
        $this->slugger = new AsciiSlugger('en');
    }

    public function generate(string $reference, string $name): string
    {
        $slug = strtolower((string) $this->slugger->slug(trim($reference.' '.$name)));

        return $slug === '' ? 'document' : $slug;
    }
}
