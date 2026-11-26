<?php

declare(strict_types=1);

namespace App\Schema;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Checks a document against the Document schema of the OpenAPI file.
 */
final class SchemaValidator
{
    public function __construct(private readonly SchemaProvider $provider)
    {
    }

    /**
     * @param array<string, mixed> $document
     *
     * @throws SchemaValidationException with every error found
     */
    public function validate(array $document): void
    {
        $data = self::toObjects($document);
        $schema = self::toObjects($this->provider->getDocumentJsonSchema());

        // every error, not only the first one: a user should see all the problems of a document at once
        $validator = (new Validator())->setStopAtFirstError(false)->setMaxErrors(100);
        $result = $validator->validate($data, $schema);
        if ($result->isValid()) {
            return;
        }

        $errors = [];
        foreach ((new ErrorFormatter())->formatKeyed($result->error()) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $path = (string) $path === '' ? '/' : (string) $path;
                // a missing field is reported on the field itself, not on the block that holds it
                if (preg_match('/^The required propert(?:y|ies) \((.+)\) (?:is|are) missing\.?$/', (string) $message, $match) === 1) {
                    foreach (array_map('trim', explode(',', $match[1])) as $field) {
                        $errors[] = ['path' => rtrim($path, '/').'/'.$field, 'message' => 'is required'];
                    }
                    continue;
                }
                $errors[] = ['path' => $path, 'message' => (string) $message];
            }
        }

        throw new SchemaValidationException($errors);
    }

    /**
     * Opis works on decoded JSON values, where objects are \stdClass, not arrays.
     */
    /**
     * @param array<string, mixed> $value
     */
    private static function toObjects(array $value): mixed
    {
        return json_decode((string) json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }
}
