<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * One output format. Services implementing it are tagged `app.publisher` and picked up automatically.
 */
interface PublisherInterface
{
    public function getName(): string;

    public function getLabel(): string;

    /**
     * @return list<OptionDefinition> the questions this format needs answered
     */
    public function getOptionDefinitions(): array;

    public function publish(PublishRequest $request): PublishResult;
}
