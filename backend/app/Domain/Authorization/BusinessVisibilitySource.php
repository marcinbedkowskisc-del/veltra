<?php

namespace App\Domain\Authorization;

enum BusinessVisibilitySource: string
{
    case Boss = 'BOSS_DERIVED';
    case Stage1d = 'STAGE_1D_INDEPENDENT';
    case RawOrganization = 'RAW_ORGANIZATION_INDEPENDENT';
}
