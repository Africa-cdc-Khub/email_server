<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Simple word+number CAPTCHA (no Google). Answers are stored in cache so
 * Bearer-token admin API clients do not need a Laravel session cookie.
 * Renders SVG (no GD extension required).
 */
class CaptchaService
{
    private const CACHE_PREFIX = 'admin_captcha:';

    private const CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function enabled(): bool
    {
        return (bool) config('services.captcha.enabled', true);
    }

    /**
     * @return array{key: string, image: string, ttl: int}
     */
    public function createChallenge(): array
    {
        $length = max(4, min(8, (int) config('services.captcha.length', 5)));
        $ttl = max(60, (int) config('services.captcha.ttl', 600));
        $code = $this->randomCode($length);
        $key = (string) Str::uuid();

        Cache::put(self::CACHE_PREFIX.$key, strtolower($code), $ttl);

        return [
            'key' => $key,
            'image' => $this->renderSvg($code),
            'ttl' => $ttl,
        ];
    }

    public function verify(?string $key, ?string $answer): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if ($key === null || trim($key) === '' || $answer === null || trim($answer) === '') {
            return false;
        }

        $cacheKey = self::CACHE_PREFIX.trim($key);
        $expected = Cache::pull($cacheKey);

        if (! is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, strtolower(trim($answer)));
    }

    /**
     * Test helper: seed a known challenge answer.
     */
    public function seedForTests(string $key, string $answer, int $ttl = 600): void
    {
        Cache::put(self::CACHE_PREFIX.$key, strtolower($answer), $ttl);
    }

    private function randomCode(int $length): string
    {
        $chars = self::CHARSET;
        $max = strlen($chars) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }

        return $code;
    }

    private function renderSvg(string $code): string
    {
        $width = 160;
        $height = 52;
        $len = strlen($code);
        $noise = '';

        for ($i = 0; $i < 6; $i++) {
            $x1 = random_int(0, $width);
            $y1 = random_int(0, $height);
            $x2 = random_int(0, $width);
            $y2 = random_int(0, $height);
            $noise .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#b4bec8" stroke-width="1"/>',
                $x1,
                $y1,
                $x2,
                $y2,
            );
        }

        $letters = '';
        $slot = $width / max(1, $len + 1);
        for ($i = 0; $i < $len; $i++) {
            $x = (int) ($slot * ($i + 0.55)) + random_int(-3, 3);
            $y = random_int(30, 40);
            $rotate = random_int(-18, 18);
            $char = htmlspecialchars($code[$i], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $letters .= sprintf(
                '<text x="%d" y="%d" fill="#212529" font-family="Menlo,Consolas,monospace" font-size="22" font-weight="700" transform="rotate(%d %d %d)">%s</text>',
                $x,
                $y,
                $rotate,
                $x,
                $y,
                $char,
            );
        }

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">'
            .'<rect width="100%%" height="100%%" fill="#f5f7fa"/>'
            .'%s%s</svg>',
            $width,
            $height,
            $width,
            $height,
            $noise,
            $letters,
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
