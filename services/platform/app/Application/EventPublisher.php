<?php

declare(strict_types=1);

namespace App\Application;

interface EventPublisher
{
    public function publish(object ...$events): void;
}
