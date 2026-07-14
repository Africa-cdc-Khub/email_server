<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Generator;

class ApiDocumentationController extends Controller
{
    public function spec(): JsonResponse
    {
        $openapi = Generator::scan([
            app_path('OpenApi'),
        ]);

        return response()->json(
            json_decode($openapi->toJson(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function ui(): Response
    {
        return response()->view('swagger.ui');
    }
}
