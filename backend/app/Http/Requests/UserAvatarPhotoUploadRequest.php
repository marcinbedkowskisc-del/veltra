<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

final class UserAvatarPhotoUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'photo' => ['required', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5120)],
        ];
    }
}
