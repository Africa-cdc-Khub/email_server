<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Rules\SafeMailHeader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExternalIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $integrationId = $this->route('external_integration')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255', new SafeMailHeader],
            'slug' => ['sometimes', 'string', 'max:64', 'alpha_dash', Rule::unique('external_integrations', 'slug')->ignore($integrationId)],
            'client_secret' => ['sometimes', 'nullable', 'string', 'min:16', 'max:255'],
            'generate_secret' => ['sometimes', 'boolean'],
            'email_provider_id' => ['sometimes', 'nullable', 'integer', 'exists:email_providers,id'],
            'provider_mailbox_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('provider_mailboxes', 'id')->where(function ($q) {
                    $providerId = $this->input('email_provider_id');
                    if ($providerId === null && $this->route('external_integration')) {
                        $providerId = $this->route('external_integration')->email_provider_id;
                    }
                    if ($providerId) {
                        $q->where('email_provider_id', $providerId);
                    } else {
                        $q->whereRaw('1 = 0');
                    }
                }),
            ],
            'allowed_ips' => ['sometimes', 'nullable', 'array'],
            'allowed_ips.*' => ['string', 'max:45', 'ip'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
