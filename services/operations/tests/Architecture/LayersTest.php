<?php

// Layer rules from docs/architecture.md §4. Domain stays framework-free; entry points (Http, Console) are outermost.

arch('domain does not depend on the framework or outer layers')
    ->expect('App\Domain')
    ->not->toUse(['Illuminate', 'App\Application', 'App\Infrastructure', 'App\Http', 'App\Console']);

arch('application depends only on the domain')
    ->expect('App\Application')
    ->not->toUse(['App\Infrastructure', 'App\Http', 'App\Console']);

arch('no debug leftovers')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();
