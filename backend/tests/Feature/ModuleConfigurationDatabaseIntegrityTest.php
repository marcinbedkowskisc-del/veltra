<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\ModuleConfigurationService;
use App\Models\Location;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;

class ModuleConfigurationDatabaseIntegrityTest extends AuthorizationTestCase
{
    private Location $kitchen;

    private Location $restaurant;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = $this->activeRestaurant();
        $this->kitchen = Location::where('code', 'CK1')->firstOrFail();
        $this->administrator = $this->user('module-integrity@example.test');
        app(MembershipService::class)->assign($this->administrator, $this->kitchen);
        RoleAssignment::create([
            'organization_id' => $this->administrator->organization_id,
            'user_id' => $this->administrator->id,
            'role' => BusinessRole::Administrator,
            'location_id' => null,
            'membership_id' => null,
        ]);
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
    }

    public function test_postgresql_accepts_valid_transitions_and_rejects_malformed_transition_shapes(): void
    {
        $this->configure(true);
        $transition = $this->transitionEvent();
        $this->assertFalse((bool) $transition->previous_enabled);
        $this->assertTrue((bool) $transition->requested_enabled);
        $this->assertTrue((bool) $transition->effective_enabled);

        $this->configure(false);
        $disabledTransition = DB::table('security_events')->where('event_kind', 'module_transition')
            ->where('requested_enabled', false)->firstOrFail();
        $this->assertTrue((bool) $disabledTransition->previous_enabled);
        $this->assertFalse((bool) $disabledTransition->effective_enabled);

        $malformed = (array) $transition;
        $malformed['id'] = Str::uuid()->toString();
        $malformed['previous_enabled'] = true;
        $malformed['requested_enabled'] = true;
        $malformed['effective_enabled'] = null;
        $this->assertDatabaseRejects('23514', fn () => DB::table('security_events')->insert($malformed));

        $malformed['id'] = Str::uuid()->toString();
        $malformed['effective_enabled'] = true;
        $this->assertDatabaseRejects('23514', fn () => DB::table('security_events')->insert($malformed));

        $malformed['id'] = Str::uuid()->toString();
        $malformed['previous_enabled'] = false;
        $malformed['requested_enabled'] = false;
        $malformed['effective_enabled'] = true;
        $this->assertDatabaseRejects('23514', fn () => DB::table('security_events')->insert($malformed));
    }

    public function test_postgresql_security_events_remain_append_only(): void
    {
        $result = $this->configure(true);
        $event = $this->transitionEvent();

        $this->assertDatabaseRejects('23514', fn () => DB::table('security_events')
            ->where('id', $event->id)->update(['action' => 'tampered']));
        $this->assertDatabaseRejects('23514', fn () => DB::table('security_events')
            ->where('id', $event->id)->delete());

        $this->assertSame(1, DB::table('security_events')->where('id', $event->id)->count());
        $this->assertSame(1, $result['changed_count']);
    }

    public function test_postgresql_state_identity_uniqueness_and_composite_foreign_key_are_enforced(): void
    {
        $this->configure(true);
        $state = (array) DB::table('location_module_states')->where('module_key', 'TEMPERATURES')->firstOrFail();

        $this->assertDatabaseRejects('23514', fn () => DB::table('location_module_states')
            ->where('id', $state['id'])->update(['location_id' => $this->kitchen->id]));

        $duplicate = $state;
        $duplicate['id'] = Str::uuid()->toString();
        $this->assertDatabaseRejects('23505', fn () => DB::table('location_module_states')->insert($duplicate));

        $mismatchedOrganization = $state;
        $mismatchedOrganization['id'] = Str::uuid()->toString();
        $mismatchedOrganization['module_key'] = 'CLEANING';
        $mismatchedOrganization['organization_id'] = Str::uuid()->toString();
        $this->assertDatabaseRejects('23503', fn () => DB::table('location_module_states')->insert($mismatchedOrganization));
    }

    /**
     * @return array<string, mixed>
     */
    private function configure(bool $enabled): array
    {
        return app(ModuleConfigurationService::class)->configure($this->administrator, $this->kitchen, [
            'module_ids' => ['TEMPERATURES'],
            'location_ids' => [$this->restaurant->id],
            'enabled' => $enabled,
            'current_password' => self::PASSWORD,
        ]);
    }

    private function transitionEvent(): object
    {
        return DB::table('security_events')->where('event_kind', 'module_transition')
            ->orderBy('occurred_at')->firstOrFail();
    }
}
