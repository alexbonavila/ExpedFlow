<?php

use cebe\openapi\Reader;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Response;

test('the OpenAPI document is valid and the Laravel response conforms to it', function () {
    $path = '/api/v1/_phase-b/status';
    $specification = base_path('tests/Fixtures/phase-b-openapi.json');
    $document = Reader::readFromJsonFile($specification);
    $this->assertTrue($document->validate(), implode(PHP_EOL, $document->getErrors()));

    Route::get($path, fn (): JsonResponse => response()->json(['status' => 'ready']));
    $result = $this->get($path)->assertOk();
    $response = new Response($result->getStatusCode(), $result->headers->all(), $result->getContent());

    (new ValidatorBuilder)
        ->fromJsonFile($specification)
        ->getResponseValidator()
        ->validate(new OperationAddress($path, 'get'), $response);
});
