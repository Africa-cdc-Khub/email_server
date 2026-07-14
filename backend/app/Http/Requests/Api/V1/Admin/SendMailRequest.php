<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SendMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string'],
            'is_html' => ['sometimes', 'boolean'],
            'provider_id' => ['sometimes', 'nullable', 'integer', 'exists:email_providers,id'],
            'cc' => ['sometimes', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['sometimes', 'array'],
            'bcc.*' => ['email'],
        ];
    }
}
