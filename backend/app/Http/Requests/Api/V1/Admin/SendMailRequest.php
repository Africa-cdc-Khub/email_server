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
    public const MAX_RECIPIENTS = 200;

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
            'to' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'to.*' => ['required', 'email', 'distinct'],
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

    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanField('is_html');
        $this->normalizeRecipientList('to', keepEmptyAsMissing: false);
        $this->normalizeRecipientList('cc');
        $this->normalizeRecipientList('bcc');
    }

    private function normalizeBooleanField(string $field): void
    {
        if (! $this->has($field)) {
            return;
        }

        $value = $this->input($field);

        if (is_bool($value)) {
            return;
        }

        if (is_string($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($normalized !== null) {
                $this->merge([$field => $normalized]);

                return;
            }
        }

        if (in_array($value, [1, 0, '1', '0'], true)) {
            $this->merge([$field => (bool) $value]);
        }
    }

    /**
     * Accept a single email string, CSV/newline paste, or an array of addresses.
     *
     * @return void
     */
    private function normalizeRecipientList(string $field, bool $keepEmptyAsMissing = true): void
    {
        if (! $this->has($field)) {
            return;
        }

        $value = $this->input($field);

        if ($value === null || $value === '' || $value === []) {
            if ($keepEmptyAsMissing) {
                $this->merge([$field => []]);
            }

            return;
        }

        if (is_array($value)) {
            $emails = $this->extractEmailsFromList($value);
            $this->merge([$field => $emails]);

            return;
        }

        if (! is_string($value)) {
            return;
        }

        $emails = $this->extractEmailsFromText($value);
        $this->merge([$field => $emails]);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function extractEmailsFromList(array $values): array
    {
        $emails = [];

        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            foreach ($this->extractEmailsFromText((string) $value) as $email) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * @return list<string>
     */
    private function extractEmailsFromText(string $value): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $matches);

        $emails = [];
        foreach ($matches[0] ?? [] as $email) {
            $emails[] = strtolower(trim($email));
        }

        return array_values(array_unique($emails));
    }
}
