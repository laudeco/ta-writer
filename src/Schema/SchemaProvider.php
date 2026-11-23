<?php

declare(strict_types=1);

namespace App\Schema;

use Symfony\Component\Yaml\Yaml;

/**
 * Single source of truth: the OpenAPI file. Parsed once per modification time of the file.
 */
final class SchemaProvider
{
    /** @var array<string, mixed>|null */
    private ?array $openApi = null;
    private ?int $loadedMtime = null;

    public function __construct(private readonly string $schemaPath)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOpenApi(): array
    {
        $mtime = @filemtime($this->schemaPath);
        if ($mtime === false) {
            throw new \RuntimeException(sprintf('OpenAPI schema not found: %s', $this->schemaPath));
        }
        if ($this->openApi === null || $this->loadedMtime !== $mtime) {
            $this->openApi = Yaml::parseFile($this->schemaPath);
            $this->loadedMtime = $mtime;
        }

        return $this->openApi;
    }

    /**
     * The Document schema with every `#/components/schemas/<Name>` reference inlined (plain JSON Schema).
     */
    /**
     * @return array<string, mixed>
     */
    public function getDocumentJsonSchema(): array
    {
        $schemas = $this->getOpenApi()['components']['schemas'] ?? [];

        return $this->inline($schemas['Document'] ?? throw new \RuntimeException('No Document schema'), $schemas, []);
    }

    /**
     * @param array<string, mixed> $schemas
     * @param list<string>         $stack   names currently being inlined, to detect cycles
     */
    private function inline(mixed $node, array $schemas, array $stack): mixed
    {
        if (\is_array($node)) {
            if (isset($node['$ref'])) {
                $name = substr((string) $node['$ref'], \strlen('#/components/schemas/'));
                if (\in_array($name, $stack, true)) {
                    throw new \LogicException(sprintf('Cyclic schema reference through %s', $name));
                }
                if (!isset($schemas[$name])) {
                    throw new \LogicException(sprintf('Unknown schema reference %s', $name));
                }

                return $this->inline($schemas[$name], $schemas, [...$stack, $name]);
            }
            $out = [];
            foreach ($node as $key => $value) {
                // vendor extensions are for the form builder only
                if (str_starts_with((string) $key, 'x-')) {
                    continue;
                }
                $out[$key] = $this->inline($value, $schemas, $stack);
            }

            return $out;
        }

        return $node;
    }
}
