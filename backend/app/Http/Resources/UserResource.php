<?php

namespace App\Http\Resources;

use App\Domain\Identity\UserAvatarType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'avatar' => [
                'type' => $this->avatar_type->value,
                'library_id' => $this->avatar_type === UserAvatarType::Library ? $this->avatar_library_id : null,
                'photo_version' => $this->avatar_type === UserAvatarType::Upload
                    ? hash('sha256', (string) $this->avatar_photo_key)
                    : null,
            ],
        ];
    }
}
