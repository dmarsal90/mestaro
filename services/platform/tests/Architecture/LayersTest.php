<?php

// Layer rules from docs/architecture.md §4. Domain stays framework-free.

arch('domain does not depend on the framework')
    ->expect('App\Domain')
    ->not->toUse(['Illuminate', 'App\Application', 'App\Infrastructure', 'App\Interface']);

arch('application depends only on the domain')
    ->expect('App\Application')
    ->not->toUse(['App\Infrastructure', 'App\Interface']);

arch('no debug leftovers')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();
