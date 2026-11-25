<?php

namespace Tests;

use App\Models\Location;
use Illuminate\Contracts\Auth\Authenticatable;

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

    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);
        $this->withSession(['identity_version' => $user->auth_version]);

        return $this;
    }

    protected function activeRestaurant(string $code = 'R1'): Location
    {
        $restaurant = $this->restaurant($code);
        $kitchen = Location::where('type', 'CENTRAL_KITCHEN')->first() ?? $this->kitchen();
        $this->service->assignManagingKitchen($restaurant, $kitchen);

        return $this->service->updateLocation($restaurant, ['is_active' => true]);
    }

    protected function primaryCentralKitchen(): Location
    {
        return Location::where('is_primary_central_kitchen', true)->first() ?? $this->kitchen();
    }
}
