<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

abstract class InstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function idempotencyKey(): string
    {
        $key = $this->header('Idempotency-Key');
        if (! is_string($key) || preg_match('/^[\x21-\x7E]{1,120}$/D', $key) !== 1) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => [__('messages.raw_materials_idempotency_key_required')],
            ]);
        }

        return $key;
    }

    protected function identifierRules(): array
    {
        return ['string', 'max:50', 'regex:/^[A-Z0-9_-]+$/'];
    }

    protected function prepareForValidation(): void
    {
        $unknown = array_diff(array_keys($this->all()), array_keys($this->rules()));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'request' => [__('messages.unknown_request_fields', ['fields' => implode(', ', $unknown)])],
            ]);
        }
    }
}
