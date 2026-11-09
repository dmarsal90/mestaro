<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\EventPublisher;

final class InMemoryEventPublisher implements EventPublisher
{
    /** @var list<object> */
    public array $published = [];

    public function publish(object ...$events): void
    {
        array_push($this->published, ...$events);
    }
}
