<?php

namespace Tests\Feature;

use App\Domain\Identity\MembershipService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\IdentityTestCase;

class PolishLocalizationTest extends IdentityTestCase
{
    public function test_polish_is_the_default_and_fallback_locale(): void
    {
        $this->assertSame('pl', config('app.locale'));
        $this->assertSame('pl', config('app.fallback_locale'));
        $this->assertSame('pl', app()->getLocale());
    }

    public function test_validation_and_password_rule_messages_are_polish(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Pole adres e-mail jest wymagane.')
            ->assertHeader('Content-Language', 'pl');

        $this->assertSame('Podane dane są nieprawidłowe.', $response->json('message'));

        $validator = Validator::make(
            ['password' => 'short'],
            ['password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()]],
        );
        $this->assertStringContainsString('co najmniej 12 znaków', $validator->errors()->first('password'));
    }

    public function test_authentication_failure_remains_generic_and_is_polish(): void
    {
        $this->user();
        $first = $this->postJson('/api/v1/auth/login', [
            'email' => 'person@example.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized()->assertHeader('Content-Language', 'pl');
        $second = $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized()->assertHeader('Content-Language', 'pl');

        $this->assertSame(__('messages.invalid_credentials'), $first->json('message'));
        $this->assertSame($first->json(), $second->json());
    }

    public function test_authorization_exception_response_is_polish(): void
    {
        $user = $this->user();
        $location = $this->kitchen();
        app(MembershipService::class)->assign($user, $location);
        $this->actingAs($user, 'web')
            ->putJson('/api/v1/auth/operating-location', ['location_id' => $location->id])
            ->assertOk();

        $this->postJson('/api/v1/security/locations', [
            'name' => 'Kitchen 2',
            'code' => 'CK2',
            'type' => 'CENTRAL_KITCHEN',
        ])->assertForbidden()
            ->assertJsonPath('message', __('messages.unauthorized'))
            ->assertHeader('Content-Language', 'pl');
    }

    public function test_framework_not_found_response_is_polish(): void
    {
        $this->getJson('/api/v1/nonexistent-route')
            ->assertNotFound()
            ->assertJsonPath('message', __('messages.resource_not_found'))
            ->assertHeader('Content-Language', 'pl');
    }

    public function test_api_never_labels_an_english_validation_error_as_polish(): void
    {
        $this->assertSame('pl', app()->getLocale());
        $this->assertSame(
            'Pole hasło musi zawierać co najmniej jedną cyfrę.',
            __('validation.password.numbers', ['attribute' => __('validation.attributes.password')]),
        );
    }
}
