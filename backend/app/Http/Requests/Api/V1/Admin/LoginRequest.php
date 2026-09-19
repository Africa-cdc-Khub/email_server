<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Rules\CaptchaAnswer;
use App\Services\CaptchaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var CaptchaService $captcha */
        $captcha = app(CaptchaService::class);

        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'captcha_key' => [
                Rule::requiredIf(fn () => $captcha->enabled()),
                'nullable',
                'string',
            ],
            'captcha' => [
                Rule::requiredIf(fn () => $captcha->enabled()),
                'nullable',
                'string',
                new CaptchaAnswer($this->input('captcha_key')),
            ],
        ];
    }
}
