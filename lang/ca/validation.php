<?php

return [
    'required' => 'El camp :attribute és obligatori.',
    'string' => 'El camp :attribute ha de ser un text.',
    'email' => 'El camp :attribute ha de ser una adreça de correu vàlida.',
    'in' => 'El valor de :attribute no és vàlid.',
    'confirmed' => 'La confirmació de :attribute no coincideix.',
    'min' => [
        'string' => 'El camp :attribute ha de tenir com a mínim :min caràcters.',
    ],
    'max' => [
        'string' => 'El camp :attribute no pot superar els :max caràcters.',
    ],
    'attributes' => [
        'name' => 'nom',
        'email' => 'correu electrònic',
        'password' => 'contrasenya',
        'password_confirmation' => 'confirmació de la contrasenya',
        'locale' => 'idioma',
        'device_name' => 'nom del dispositiu',
        'token' => 'testimoni de recuperació',
    ],
];
