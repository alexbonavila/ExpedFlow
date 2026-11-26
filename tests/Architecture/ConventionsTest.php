<?php

use Illuminate\Database\Eloquent\Model;

arch('application code contains no debug dumping calls')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray']);

arch('application models are Eloquent models')
    ->expect('App\Models')
    ->toExtend(Model::class);
