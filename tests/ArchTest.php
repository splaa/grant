<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), exit(), or app()')
    ->expect(['dd', 'ddd', 'env', 'exit', 'app'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Shipfastlabs\Grant')
    ->toUseStrictTypes();

arch('the package source does not use Eloquent')
    ->expect('Shipfastlabs\Grant')
    ->not->toUse('Illuminate\Database\Eloquent');
