<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Identity\UserAvatarType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\AuthorizationTestCase;

class UserAvatarTest extends AuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('user_avatars');
    }

    public function test_user_can_select_library_avatar_and_restore_initials_with_audited_persistence(): void
    {
        $user = $this->provisionedUser('person@example.test', BusinessRole::Boss);
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/avatar', [
            'type' => 'LIBRARY',
            'library_id' => 'portrait-040',
        ])->assertOk();

        $this->assertSame(UserAvatarType::Library, $user->fresh()->avatar_type);
        $this->assertSame('portrait-040', $user->fresh()->avatar_library_id);
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.avatar.type', 'LIBRARY')
            ->assertJsonPath('data.avatar.library_id', 'portrait-040');
        $this->assertDatabaseHas('security_events', [
            'actor_id' => $user->id,
            'target_id' => $user->id,
            'action' => 'account.profile_update',
            'outcome' => 'accepted',
        ]);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()
            ->assertJsonPath('data.avatar.type', 'LIBRARY')
            ->assertJsonPath('data.avatar.library_id', 'portrait-040');

        $this->deleteJson('/api/v1/auth/avatar')->assertOk();
        $this->assertSame(UserAvatarType::Initials, $user->fresh()->avatar_type);
        $this->assertNull($user->fresh()->avatar_library_id);
        $this->assertNull($user->fresh()->avatar_photo_key);
    }

    public function test_uploads_are_reencoded_stored_privately_replaced_and_removed_safely(): void
    {
        $user = $this->provisionedUser('person@example.test', BusinessRole::Boss);
        $this->actingAs($user, 'web');
        $this->post('/api/v1/auth/avatar/photo', ['photo' => UploadedFile::fake()->image('photo.png', 64, 64)])
            ->assertOk();
        $firstKey = (string) $user->fresh()->avatar_photo_key;
        $firstPath = "users/{$user->id}/{$firstKey}.webp";
        $this->assertSame(UserAvatarType::Upload, $user->fresh()->avatar_type);
        Storage::disk('user_avatars')->assertExists($firstPath);
        $this->getJson('/api/v1/auth/me')->assertJsonPath(
            'data.avatar.photo_version',
            hash('sha256', $firstKey),
        );
        $this->assertSame([256, 256], array_slice(getimagesizefromstring(Storage::disk('user_avatars')->get($firstPath)), 0, 2));
        $this->get('/api/v1/auth/avatar/photo')->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->post('/api/v1/auth/avatar/photo', ['photo' => UploadedFile::fake()->image('replacement.jpg', 96, 96)])
            ->assertOk();
        $secondKey = (string) $user->fresh()->avatar_photo_key;
        $this->assertNotSame($firstKey, $secondKey);
        Storage::disk('user_avatars')->assertMissing($firstPath);
        Storage::disk('user_avatars')->assertExists("users/{$user->id}/{$secondKey}.webp");

        $this->deleteJson('/api/v1/auth/avatar')->assertOk();
        $this->assertSame(UserAvatarType::Initials, $user->fresh()->avatar_type);
        Storage::disk('user_avatars')->assertMissing("users/{$user->id}/{$secondKey}.webp");
    }

    public function test_invalid_images_and_unapproved_library_ids_are_rejected_without_replacing_avatar(): void
    {
        $user = $this->provisionedUser('person@example.test', BusinessRole::Boss);
        $this->actingAs($user, 'web');

        $this->putJson('/api/v1/auth/avatar', ['type' => 'LIBRARY', 'library_id' => 'portrait-999'])
            ->assertUnprocessable();
        $this->post('/api/v1/auth/avatar/photo', [
            'photo' => UploadedFile::fake()->createWithContent('avatar.svg', '<svg><script>alert(1)</script></svg>'),
        ])->assertUnprocessable();

        $this->assertSame(UserAvatarType::Initials, $user->fresh()->avatar_type);
        $this->assertSame([], Storage::disk('user_avatars')->allFiles());
    }

    public function test_administrator_can_manage_only_authorized_organization_users(): void
    {
        $location = $this->activeRestaurant();
        $admin = $this->provisionedUser('admin@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $location);
        $this->selectContext($admin, $location);
        $target = $this->provisionedUser('target@example.test', BusinessRole::Employee, $location);

        $this->putJson("/api/v1/administration/users/{$target->id}/avatar", [
            'type' => 'LIBRARY',
            'library_id' => 'abstract-024',
        ])->assertOk();
        $this->assertSame(UserAvatarType::Library, $target->fresh()->avatar_type);
        $this->getJson("/api/v1/administration/users/{$target->id}")
            ->assertOk()->assertJsonPath('data.avatar.type', 'LIBRARY')
            ->assertJsonPath('data.avatar.library_id', 'abstract-024')
            ->assertJsonPath('data.history.0.action', 'account.profile_update');
        $this->post("/api/v1/administration/users/{$target->id}/avatar/photo", [
            'photo' => UploadedFile::fake()->image('managed.png', 80, 80),
        ])->assertOk();
        $targetPhotoKey = (string) $target->fresh()->avatar_photo_key;
        $this->assertSame(UserAvatarType::Upload, $target->fresh()->avatar_type);
        Storage::disk('user_avatars')->assertExists("users/{$target->id}/{$targetPhotoKey}.webp");
        $this->get("/api/v1/administration/users/{$target->id}/avatar/photo")
            ->assertOk()->assertHeader('Content-Type', 'image/webp');

        $manager = $this->provisionedUser('manager@example.test', BusinessRole::RestaurantManager, $location);
        Auth::forgetGuards();
        $this->flushSession();
        $this->actingAs($manager, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $location->id,
        ])->assertOk();
        $this->putJson("/api/v1/administration/users/{$target->id}/avatar", [
            'type' => 'INITIALS',
        ])->assertForbidden();
        $this->get("/api/v1/administration/users/{$target->id}/avatar/photo")->assertForbidden();
        $this->assertSame(UserAvatarType::Upload, $target->fresh()->avatar_type);
    }

    public function test_self_service_never_uses_a_supplied_user_id_as_the_mutation_target(): void
    {
        $user = $this->provisionedUser('person@example.test', BusinessRole::Boss);
        $other = $this->provisionedUser('other@example.test', BusinessRole::Boss);
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/avatar', [
            'type' => 'LIBRARY',
            'library_id' => 'portrait-001',
            'user_id' => $other->id,
        ])->assertUnprocessable();

        $this->assertSame(UserAvatarType::Initials, $user->fresh()->avatar_type);
        $this->assertSame(UserAvatarType::Initials, $other->fresh()->avatar_type);
    }

    private function selectContext(User $user, Location $location): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $location->id,
        ])->assertOk();
    }
}
