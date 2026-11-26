<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

if (app()->environment('testing')) {
    Route::get('/_phase-b', function () {
        abort_unless(app()->environment('testing'), 404);

        return view('testing.phase-b');
    });
}
