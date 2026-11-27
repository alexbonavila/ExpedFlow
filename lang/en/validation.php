<?php

return [
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute must be a string.',
    'email' => 'The :attribute must be a valid email address.',
    'in' => 'The selected :attribute is invalid.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'array' => 'The :attribute must be an array.',
    'list' => 'The :attribute must be a list.',
    'distinct' => 'The :attribute has a duplicate value.',
    'enum' => 'The :attribute has an invalid value.',
    'present' => 'The :attribute must be present.',
    'integer' => 'The :attribute must be an integer.',
    'min' => [
        'numeric' => 'The :attribute must be at least :min.',
        'string' => 'The :attribute must have at least :min characters.',
    ],
    'max' => [
        'array' => 'The :attribute must not have more than :max items.',
        'string' => 'The :attribute must not exceed :max characters.',
    ],
    'attributes' => [
        'permissions' => 'permissions',
        'role_ids' => 'role IDs',
        'name' => 'name',
        'email' => 'email address',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'locale' => 'language',
        'device_name' => 'device name',
        'token' => 'recovery token',
    ],
];
