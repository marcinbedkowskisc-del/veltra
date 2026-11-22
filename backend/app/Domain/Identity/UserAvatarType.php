<?php

namespace App\Domain\Identity;

enum UserAvatarType: string
{
    case Initials = 'INITIALS';
    case Library = 'LIBRARY';
    case Upload = 'UPLOAD';
}
