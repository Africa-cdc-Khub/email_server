<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BrandingSetting;
use Illuminate\Http\JsonResponse;

class BrandingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => BrandingSetting::current()->toPublicArray()]);
    }
}
