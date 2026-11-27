<?php

return [
    'required' => 'El camp :attribute és obligatori.',
    'string' => 'El camp :attribute ha de ser un text.',
    'email' => 'El camp :attribute ha de ser una adreça de correu vàlida.',
    'in' => 'El valor de :attribute no és vàlid.',
    'confirmed' => 'La confirmació de :attribute no coincideix.',
    'array' => 'El camp :attribute ha de ser una matriu.',
    'list' => 'El camp :attribute ha de ser una llista.',
    'distinct' => 'El camp :attribute conté un valor duplicat.',
    'enum' => 'El camp :attribute conté un valor no vàlid.',
    'present' => 'El camp :attribute ha de ser present.',
    'integer' => 'El camp :attribute ha de ser un nombre enter.',
    'min' => [
        'numeric' => 'El camp :attribute ha de ser com a mínim :min.',
        'string' => 'El camp :attribute ha de tenir com a mínim :min caràcters.',
    ],
    'max' => [
        'array' => 'El camp :attribute no ha de tenir més de :max elements.',
        'string' => 'El camp :attribute no pot superar els :max caràcters.',
    ],
    'attributes' => [
        'permissions' => 'permisos',
        'role_ids' => 'identificadors de rols',
        'name' => 'nom',
        'email' => 'correu electrònic',
        'password' => 'contrasenya',
        'password_confirmation' => 'confirmació de la contrasenya',
        'locale' => 'idioma',
        'device_name' => 'nom del dispositiu',
        'token' => 'testimoni de recuperació',
    ],
];
