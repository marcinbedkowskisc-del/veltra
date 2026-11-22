<?php

namespace App\Http\Requests;

use App\Domain\Identity\UserAvatarService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserAvatarSelectionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'type' => ['required', Rule::in(['INITIALS', 'LIBRARY'])],
            'library_id' => [
                Rule::requiredIf($this->input('type') === 'LIBRARY'),
                Rule::prohibitedIf($this->input('type') === 'INITIALS'),
                'nullable',
                'string',
                Rule::in(UserAvatarService::libraryIds()),
            ],
        ];
    }
}
