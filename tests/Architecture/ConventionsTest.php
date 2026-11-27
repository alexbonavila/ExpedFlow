<?php

use Illuminate\Database\Eloquent\Model;

arch('application code contains no debug dumping calls')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray']);

arch('application models are Eloquent models')
    ->expect('App\Models')
    ->toExtend(Model::class);

arch('HTTP controllers delegate role persistence to authorization services')
    ->expect('App\Http\Controllers')
    ->not->toUse('Spatie\Permission\Models\Role');

arch('identity code reads bootstrap settings through Laravel configuration')
    ->expect(['App\Console\Commands', 'App\Support'])
    ->not->toUse('env');
