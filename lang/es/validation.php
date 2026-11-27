<?php

return [
    'required' => 'El campo :attribute es obligatorio.',
    'string' => 'El campo :attribute debe ser un texto.',
    'email' => 'El campo :attribute debe ser una dirección de correo válida.',
    'in' => 'El valor de :attribute no es válido.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'array' => 'El campo :attribute debe ser una matriz.',
    'list' => 'El campo :attribute debe ser una lista.',
    'distinct' => 'El campo :attribute contiene un valor duplicado.',
    'enum' => 'El campo :attribute contiene un valor no válido.',
    'present' => 'El campo :attribute debe estar presente.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'min' => [
        'numeric' => 'El campo :attribute debe ser como mínimo :min.',
        'string' => 'El campo :attribute debe tener como mínimo :min caracteres.',
    ],
    'max' => [
        'array' => 'El campo :attribute no debe tener más de :max elementos.',
        'string' => 'El campo :attribute no debe superar los :max caracteres.',
    ],
    'attributes' => [
        'permissions' => 'permisos',
        'role_ids' => 'identificadores de roles',
        'name' => 'nombre',
        'email' => 'correo electrónico',
        'password' => 'contraseña',
        'password_confirmation' => 'confirmación de contraseña',
        'locale' => 'idioma',
        'device_name' => 'nombre del dispositivo',
        'token' => 'token de recuperación',
    ],
];
