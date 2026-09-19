<?php

namespace App\Rules;

use App\Services\CaptchaService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CaptchaAnswer implements ValidationRule
{
    public function __construct(
        private readonly ?string $captchaKey,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var CaptchaService $captcha */
        $captcha = app(CaptchaService::class);

        if (! $captcha->enabled()) {
            return;
        }

        if (! is_string($value) || ! $captcha->verify($this->captchaKey, $value)) {
            $fail('The captcha answer is incorrect or has expired. Refresh and try again.');
        }
    }
}
