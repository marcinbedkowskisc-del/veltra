<?php

namespace Tests;

use App\Models\Location;
use App\Models\Organization;
use App\Models\User;

abstract class IdentityTestCase extends InstallationTestCase
{
    protected const PASSWORD = 'Test-Only-Password!42';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'session.driver' => 'array']);
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->initialize();
    }

    protected function user(string $email = 'person@example.test'): User
    {
        return User::create([
            'organization_id' => Organization::firstOrFail()->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'email' => $email, 'password' => self::PASSWORD, 'is_active' => true,
        ]);
    }

    protected function activeRestaurant(string $code = 'R1'): Location
    {
        $restaurant = $this->restaurant($code);
        $kitchen = Location::where('type', 'CENTRAL_KITCHEN')->first() ?? $this->kitchen();
        $this->service->assignManagingKitchen($restaurant, $kitchen);

        return $this->service->updateLocation($restaurant, ['is_active' => true]);
    }
}
