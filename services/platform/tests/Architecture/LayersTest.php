<?php

// Layer rules from docs/architecture.md §4. Domain stays framework-free; entry points (Http, Console) are outermost.

arch('domain does not depend on the framework')
    ->expect('App\Domain')
    ->not->toUse(['Illuminate', 'Filament', 'App\Application', 'App\Infrastructure', 'App\Http', 'App\Console', 'App\Filament']);

arch('application depends only on the domain')
    ->expect('App\Application')
    ->not->toUse(['Filament', 'App\Infrastructure', 'App\Http', 'App\Console', 'App\Filament']);

arch('no debug leftovers')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();
