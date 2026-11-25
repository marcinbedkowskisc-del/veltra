<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationTestCase;

class SupplierDirectoryTest extends AuthorizationTestCase
{
    private Location $location;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->location = $this->kitchen();
        $this->administrator = $this->provisionedUser('supplier-directory-admin@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->administrator, $this->location);
        $this->selectContext($this->administrator);
    }

    public function test_administrator_can_manage_supplier_company_and_contacts_without_changing_supplier_identity(): void
    {
        $created = $this->postJson('/api/v1/administration/suppliers', [
            'legal_name' => 'BANASIK sp. z o.o.',
            'display_name' => 'Dostawca BANASIK',
            'tax_identifier' => '123-456-78-90',
            'street_address' => 'ul. Przykładowa 10',
            'city' => 'Kraków',
            'phone' => '+48 12 123 45 67',
            'email' => 'biuro@example.test',
            'website' => 'https://example.test',
        ], ['Idempotency-Key' => 'supplier-directory-create-001'])
            ->assertCreated()
            ->assertJsonMissingPath('data.supplier_code');
        $supplierId = $created->json('data.id');
        $supplier = DB::table('organization_suppliers')->where('id', $supplierId)->first();

        $this->assertSame('BANASIK sp. z o.o.', $supplier->legal_name);
        $this->assertNotSame('123-456-78-90', $supplier->tax_identifier_ciphertext);
        $this->assertSame('123-456-78-90', Crypt::decryptString($supplier->tax_identifier_ciphertext));
        $this->assertSame(64, strlen(trim($supplier->tax_identifier_fingerprint)));

        $this->getJson('/api/v1/administration/suppliers?status=active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $supplierId)
            ->assertJsonPath('data.0.display_name', 'Dostawca BANASIK')
            ->assertJsonPath('data.0.tax_identifier', '1234567890')
            ->assertJsonMissingPath('data.0.supplier_code');

        $contact = $this->postJson('/api/v1/administration/suppliers/'.$supplierId.'/contacts', [
            'full_name' => 'Anna Nowak',
            'position' => 'Opiekun klienta',
            'phone' => '+48 12 123 45 67',
            'email' => 'anna@example.test',
            'is_primary' => true,
        ], ['Idempotency-Key' => 'supplier-directory-contact-001'])
            ->assertCreated();
        $contactId = $contact->json('data.id');

        $details = $this->getJson('/api/v1/administration/suppliers/'.$supplierId)
            ->assertOk()
            ->assertJsonPath('data.contacts.0.id', $contactId)
            ->assertJsonPath('data.contacts.0.is_primary', true)
            ->assertJsonPath('data.contacts.0.email', 'anna@example.test');

        $this->patchJson('/api/v1/administration/suppliers/'.$supplierId, [
            'legal_name' => 'BANASIK spółka z ograniczoną odpowiedzialnością',
        ], ['Idempotency-Key' => 'supplier-directory-update-001'])
            ->assertOk()->assertJsonPath('data.id', $supplierId);
        $this->assertSame(
            'BANASIK spółka z ograniczoną odpowiedzialnością',
            DB::table('organization_suppliers')->where('id', $supplierId)->value('legal_name'),
        );
        $this->assertDatabaseHas('raw_materials_commercial_audit_events', [
            'subject_type' => 'SUPPLIER',
            'subject_id' => $supplierId,
            'event_code' => 'SUPPLIER_DETAILS_CORRECTED',
        ]);
        $this->assertDatabaseHas('raw_materials_commercial_audit_events', [
            'subject_type' => 'SUPPLIER_CONTACT',
            'subject_id' => $contactId,
            'event_code' => 'SUPPLIER_CONTACT_CREATED',
        ]);
        $this->assertSame($supplierId, $details->json('data.id'));
    }

    public function test_duplicate_tax_identifiers_are_rejected_and_archive_restore_preserve_history(): void
    {
        $first = $this->postJson('/api/v1/administration/suppliers', [
            'legal_name' => 'Pierwszy Dostawca',
            'tax_identifier' => '9876543210',
        ], ['Idempotency-Key' => 'supplier-directory-duplicate-001'])->assertCreated();
        $supplierId = $first->json('data.id');

        $this->postJson('/api/v1/administration/suppliers', [
            'legal_name' => 'Drugi Dostawca',
            'tax_identifier' => '987-654-32-10',
        ], ['Idempotency-Key' => 'supplier-directory-duplicate-002'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tax_identifier');

        $this->postJson('/api/v1/administration/suppliers/'.$supplierId.'/archive', [], [
            'Idempotency-Key' => 'supplier-directory-archive-001',
        ])->assertOk()->assertJsonPath('data.lifecycle_state', 'ARCHIVED');
        $this->assertDatabaseHas('organization_suppliers', ['id' => $supplierId, 'lifecycle_state' => 'ARCHIVED']);
        $this->postJson('/api/v1/administration/suppliers/'.$supplierId.'/restore', [], [
            'Idempotency-Key' => 'supplier-directory-restore-001',
        ])->assertOk()->assertJsonPath('data.lifecycle_state', 'ACTIVE');
        $this->assertDatabaseHas('organization_suppliers', ['id' => $supplierId, 'lifecycle_state' => 'ACTIVE']);
        $this->assertDatabaseHas('raw_materials_commercial_audit_events', [
            'subject_type' => 'SUPPLIER',
            'subject_id' => $supplierId,
            'event_code' => 'SUPPLIER_ARCHIVED',
        ]);
        $this->assertDatabaseHas('raw_materials_commercial_audit_events', [
            'subject_type' => 'SUPPLIER',
            'subject_id' => $supplierId,
            'event_code' => 'SUPPLIER_RESTORED',
        ]);
    }

    public function test_contact_primary_status_is_unique_and_removal_keeps_the_record(): void
    {
        $supplierId = $this->postJson('/api/v1/administration/suppliers', [
            'legal_name' => 'Dostawca Kontaktowy',
        ], ['Idempotency-Key' => 'supplier-directory-contacts-001'])->assertCreated()->json('data.id');

        $firstId = $this->postJson('/api/v1/administration/suppliers/'.$supplierId.'/contacts', [
            'full_name' => 'Anna Nowak',
            'is_primary' => true,
        ], ['Idempotency-Key' => 'supplier-directory-contacts-002'])->assertCreated()->json('data.id');
        $secondId = $this->postJson('/api/v1/administration/suppliers/'.$supplierId.'/contacts', [
            'full_name' => 'Jan Kowalski',
            'is_primary' => true,
        ], ['Idempotency-Key' => 'supplier-directory-contacts-003'])->assertCreated()->json('data.id');

        $this->assertSame(1, DB::table('supplier_contacts')->where('supplier_id', $supplierId)
            ->where('lifecycle_state', 'ACTIVE')->where('is_primary', true)->count());
        $this->assertDatabaseHas('supplier_contacts', ['id' => $firstId, 'is_primary' => false]);

        $this->deleteJson('/api/v1/administration/suppliers/'.$supplierId.'/contacts/'.$secondId, [], [
            'Idempotency-Key' => 'supplier-directory-contacts-004',
        ])->assertOk();
        $this->assertDatabaseHas('supplier_contacts', [
            'id' => $secondId,
            'lifecycle_state' => 'REMOVED',
            'is_primary' => false,
        ]);
        $this->assertDatabaseHas('raw_materials_commercial_audit_events', [
            'subject_type' => 'SUPPLIER_CONTACT',
            'subject_id' => $secondId,
            'event_code' => 'SUPPLIER_CONTACT_REMOVED',
        ]);
    }

    public function test_non_administrator_cannot_view_supplier_directory(): void
    {
        $user = $this->provisionedUser('supplier-directory-user@example.test', BusinessRole::CentralKitchenChef, $this->location);
        $this->selectContext($user);

        $this->getJson('/api/v1/administration/suppliers')->assertForbidden();
    }

    private function selectContext(User $user): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $this->location->id,
        ])->assertOk();
    }
}
