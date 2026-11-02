<?php

namespace App\Domain\Authorization;

enum BusinessRole: string
{
    case Administrator = 'ADMINISTRATOR';
    case CentralKitchenChef = 'CENTRAL_KITCHEN_CHEF';
    case Boss = 'BOSS';
    case RestaurantManager = 'RESTAURANT_MANAGER';
    case RestaurantChef = 'RESTAURANT_CHEF';
    case Employee = 'EMPLOYEE';

    public function organizationWide(): bool
    {
        return in_array($this, [self::Administrator, self::Boss], true);
    }

    public function protectedPersonnel(): bool
    {
        return ! in_array($this, [self::Employee, self::RestaurantChef], true);
    }
}
