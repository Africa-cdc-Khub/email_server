<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Rules\CaptchaAnswer;
use App\Rules\SafeMailHeader;
use App\Services\CaptchaService;
use App\Services\EmailAttachmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        /** @var CaptchaService $captcha */
        $captcha = app(CaptchaService::class);
        /** @var EmailAttachmentService $attachments */
        $attachments = app(EmailAttachmentService::class);

        return array_merge([
            'to' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:500', new SafeMailHeader],
            'body' => ['required', 'string'],
            'is_html' => ['sometimes', 'boolean'],
            'provider_id' => ['sometimes', 'nullable', 'integer', 'exists:email_providers,id'],
            'cc' => ['sometimes', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['sometimes', 'array'],
            'bcc.*' => ['email'],
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
        ], $attachments->validationRules());
    }
}
