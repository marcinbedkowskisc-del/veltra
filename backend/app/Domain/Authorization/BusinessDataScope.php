<?php

namespace App\Domain\Authorization;

enum BusinessDataScope: string
{
    case Organization = 'ORGANIZATION';
    case Location = 'LOCATION';
    case Derived = 'DERIVED';
    case Unknown = 'UNKNOWN';
}
