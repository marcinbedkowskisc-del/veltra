<?php

namespace App\Domain\Authorization;

enum BossRawCatalogIntent: string
{
    case List = 'list';
    case Detail = 'detail';
}
