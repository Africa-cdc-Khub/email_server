<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Rules\CaptchaAnswer;
use App\Services\CaptchaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterAccountRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['required', 'string', 'max:40'],
            'organisation' => ['required', 'string', 'max:255'],
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
