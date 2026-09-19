<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\CaptchaService;
use Illuminate\Http\JsonResponse;

class CaptchaController extends Controller
{
    public function __invoke(CaptchaService $captcha): JsonResponse
    {
        if (! $captcha->enabled()) {
            return response()->json([
                'data' => [
                    'enabled' => false,
                    'key' => null,
                    'image' => null,
                ],
            ]);
        }

        $challenge = $captcha->createChallenge();

        return response()->json([
            'data' => [
                'enabled' => true,
                'key' => $challenge['key'],
                'image' => $challenge['image'],
                'ttl' => $challenge['ttl'],
            ],
        ]);
    }
}
