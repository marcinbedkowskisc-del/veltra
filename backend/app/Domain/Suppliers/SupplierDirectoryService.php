<?php

namespace App\Domain\Suppliers;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\RawMaterials\RawMaterialCommercialService;
use App\Domain\RawMaterials\RawMaterialException;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupplierDirectoryService
{
    public function __construct(
        private RawMaterialCommercialService $commercial,
        private AuthorizationMutationContext $contexts,
    ) {}

    public function list(User $actor, Location $context, array $filters): array
    {
        $this->commercial->assertAdministrationSupplierAccess($actor, $context);

        $query = DB::table('organization_suppliers as suppliers')
            ->where('suppliers.organization_id', $actor->organization_id)
            ->leftJoin('supplier_contacts as primary_contacts', function ($join): void {
                $join->on('primary_contacts.supplier_id', '=', 'suppliers.id')
                    ->on('primary_contacts.organization_id', '=', 'suppliers.organization_id')
                    ->where('primary_contacts.lifecycle_state', 'ACTIVE')
                    ->where('primary_contacts.is_primary', true);
            })
            ->select([
                'suppliers.id',
                'suppliers.display_name',
                'suppliers.legal_name',
                'suppliers.tax_identifier_ciphertext',
                'suppliers.street_address',
                'suppliers.city',
                'suppliers.phone',
                'suppliers.email',
                'suppliers.website',
                'suppliers.lifecycle_state',
                'primary_contacts.full_name as primary_contact',
            ]);

        if (($filters['status'] ?? 'active') !== 'all') {
            $query->where('suppliers.lifecycle_state', strtoupper($filters['status'] ?? 'active'));
        }

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $search = trim($filters['search']);
            $fingerprint = SupplierTaxIdentifier::normalize($search) === ''
                ? null
                : SupplierTaxIdentifier::fingerprint($search);
            $escaped = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($matches) use ($escaped, $fingerprint): void {
                $matches->where('suppliers.legal_name', 'ilike', $escaped)
                    ->orWhere('suppliers.display_name', 'ilike', $escaped)
                    ->orWhere('suppliers.city', 'ilike', $escaped)
                    ->orWhereExists(fn ($contacts) => $contacts->selectRaw('1')
                        ->from('supplier_contacts')
                        ->whereColumn('supplier_contacts.supplier_id', 'suppliers.id')
                        ->whereColumn('supplier_contacts.organization_id', 'suppliers.organization_id')
                        ->where('supplier_contacts.lifecycle_state', 'ACTIVE')
                        ->where('supplier_contacts.full_name', 'ilike', $escaped));
                if ($fingerprint !== null) {
                    $matches->orWhere('suppliers.tax_identifier_fingerprint', $fingerprint);
                }
            });
        }

        $sortable = [
            'display_name' => 'suppliers.display_name',
            'legal_name' => 'suppliers.legal_name',
            'tax_identifier' => 'suppliers.tax_identifier_fingerprint',
            'city' => 'suppliers.city',
            'primary_contact' => 'primary_contacts.full_name',
            'status' => 'suppliers.lifecycle_state',
        ];
        $sort = $sortable[$filters['sort'] ?? 'display_name'];
        $direction = $filters['direction'] ?? 'asc';
        $page = $query->orderBy($sort, $direction)
            ->orderBy('suppliers.id')
            ->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);
        $supplierIds = $page->getCollection()->pluck('id')->all();
        $materialCounts = $this->materialCounts($actor->organization_id, $supplierIds);

        return [
            'data' => $page->getCollection()->map(fn (object $row): array => [
                'id' => $row->id,
                'display_name' => $row->display_name,
                'legal_name' => $row->legal_name ?? $row->display_name,
                'tax_identifier' => $this->decrypt($row->tax_identifier_ciphertext),
                'city' => $row->city,
                'primary_contact' => $row->primary_contact,
                'lifecycle_state' => $row->lifecycle_state,
                'raw_material_count' => $materialCounts[$row->id] ?? 0,
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function show(User $actor, Location $context, string $supplierId): array
    {
        $this->commercial->assertAdministrationSupplierAccess($actor, $context);
        $supplier = $this->supplierRow($actor, $supplierId);

        return ['data' => $this->presentSupplier($supplier, $actor->organization_id)];
    }

    public function similarNames(
        User $actor,
        Location $context,
        string $officialName,
        ?string $excludeSupplierId = null,
    ): array {
        $this->commercial->assertAdministrationSupplierAccess($actor, $context);
        $search = $this->normalizeCompanyName($officialName);
        if ($search === '') {
            return ['data' => []];
        }

        $matches = [];
        DB::table('organization_suppliers')
            ->where('organization_id', $actor->organization_id)
            ->where('lifecycle_state', 'ACTIVE')
            ->when($excludeSupplierId, fn ($query) => $query->where('id', '<>', $excludeSupplierId))
            ->get(['id', 'legal_name', 'display_name'])
            ->each(function (object $supplier) use ($search, &$matches): void {
                $score = 0.0;
                foreach ([$supplier->legal_name, $supplier->display_name] as $name) {
                    if ($name === null) {
                        continue;
                    }
                    similar_text($search, $this->normalizeCompanyName($name), $candidateScore);
                    $score = max($score, $candidateScore);
                }
                if ($score >= 78) {
                    $matches[] = [
                        'id' => $supplier->id,
                        'legal_name' => $supplier->legal_name ?? $supplier->display_name,
                        'display_name' => $supplier->display_name,
                        'similarity' => (int) round($score),
                    ];
                }
            });

        usort($matches, fn (array $left, array $right): int => $right['similarity'] <=> $left['similarity']);

        return ['data' => array_slice($matches, 0, 5)];
    }

    public function create(
        User $actor,
        Location $context,
        array $input,
        string $idempotencyKey,
    ): array {
        return $this->commercial->runAdministrationSupplierMutation(
            $actor,
            $context,
            'administration.supplier.create',
            $idempotencyKey,
            $input,
            function (string $operationId) use ($actor, $context, $input): array {
                $taxIdentifier = $this->nullableText($input['tax_identifier'] ?? null);
                if ($taxIdentifier !== null) {
                    $this->assertTaxIdentifierAvailable($actor->organization_id, $taxIdentifier);
                }

                $id = Str::uuid()->toString();
                $supplierCode = 'SUP-'.strtoupper(str_replace('-', '', Str::uuid()->toString()));
                DB::table('organization_suppliers')->insert([
                    'id' => $id,
                    'organization_id' => $actor->organization_id,
                    'supplier_code' => $supplierCode,
                    'display_name' => $this->nullableText($input['display_name'] ?? null),
                    'legal_name' => trim($input['legal_name']),
                    'tax_identifier_ciphertext' => $taxIdentifier === null ? null : Crypt::encryptString($taxIdentifier),
                    'tax_identifier_fingerprint' => $taxIdentifier === null
                        ? null
                        : SupplierTaxIdentifier::fingerprint($taxIdentifier),
                    'street_address' => $this->nullableText($input['street_address'] ?? null),
                    'city' => $this->nullableText($input['city'] ?? null),
                    'phone' => $this->nullableText($input['phone'] ?? null),
                    'email' => $this->nullableText($input['email'] ?? null),
                    'website' => $this->nullableText($input['website'] ?? null),
                    'created_by' => $actor->id,
                ]);
                $this->commercial->auditAdministrationSupplierChange(
                    $actor,
                    $context,
                    $operationId,
                    'SUPPLIER',
                    $id,
                    'SUPPLIER_CREATED',
                    null,
                    ['id' => $id, 'lifecycle_state' => 'ACTIVE'],
                    ['changed_fields' => array_keys($input)],
                );

                return ['data' => ['id' => $id]];
            },
        );
    }

    public function update(
        User $actor,
        Location $context,
        string $supplierId,
        array $input,
        string $idempotencyKey,
    ): array {
        return $this->commercial->runAdministrationSupplierMutation(
            $actor,
            $context,
            'administration.supplier.update',
            $idempotencyKey,
            ['supplier_id' => $supplierId, ...$input],
            function (string $operationId) use ($actor, $context, $supplierId, $input): array {
                $supplier = $this->supplierRow($actor, $supplierId, lock: true);
                if ($supplier->lifecycle_state !== 'ACTIVE') {
                    $this->conflict('Dostawca jest archiwalny. Przywróć go przed edycją.');
                }

                $updates = [];
                $changedFields = [];
                foreach (['display_name', 'legal_name', 'street_address', 'city', 'phone', 'email', 'website'] as $field) {
                    if (array_key_exists($field, $input)) {
                        $value = $field === 'legal_name' ? trim($input[$field]) : $this->nullableText($input[$field]);
                        if ($value !== $supplier->{$field}) {
                            $updates[$field] = $value;
                            $changedFields[] = $field;
                        }
                    }
                }

                $taxIdentifier = $supplier->tax_identifier_ciphertext === null
                    ? null
                    : Crypt::decryptString($supplier->tax_identifier_ciphertext);
                if (array_key_exists('tax_identifier', $input)) {
                    $requestedTaxIdentifier = $this->nullableText($input['tax_identifier']);
                    if ($requestedTaxIdentifier !== $taxIdentifier) {
                        if ($requestedTaxIdentifier !== null) {
                            $this->assertTaxIdentifierAvailable($actor->organization_id, $requestedTaxIdentifier, $supplierId);
                        }
                        $updates['tax_identifier_ciphertext'] = $requestedTaxIdentifier === null
                            ? null
                            : Crypt::encryptString($requestedTaxIdentifier);
                        $updates['tax_identifier_fingerprint'] = $requestedTaxIdentifier === null
                            ? null
                            : SupplierTaxIdentifier::fingerprint($requestedTaxIdentifier);
                        $changedFields[] = 'tax_identifier';
                    }
                }

                if ($updates !== []) {
                    $updates['updated_at'] = now();
                    $this->contexts->runForSubject(
                        AuthorizationMutationScope::rawMaterialSupplierCorrection(),
                        $actor->id,
                        $supplierId,
                        fn (): int => DB::table('organization_suppliers')
                            ->where('organization_id', $actor->organization_id)
                            ->where('id', $supplierId)
                            ->update($updates),
                    );
                    $this->commercial->auditAdministrationSupplierChange(
                        $actor,
                        $context,
                        $operationId,
                        'SUPPLIER',
                        $supplierId,
                        'SUPPLIER_DETAILS_CORRECTED',
                        $this->supplierAuditState($supplier),
                        ['id' => $supplierId, 'lifecycle_state' => 'ACTIVE'],
                        ['changed_fields' => $changedFields],
                    );
                }

                return ['data' => ['id' => $supplierId]];
            },
        );
    }

    public function archive(
        User $actor,
        Location $context,
        string $supplierId,
        string $idempotencyKey,
    ): array {
        return $this->commercial->runAdministrationSupplierMutation(
            $actor,
            $context,
            'administration.supplier.archive',
            $idempotencyKey,
            ['supplier_id' => $supplierId],
            function (string $operationId) use ($actor, $context, $supplierId): array {
                $supplier = $this->supplierRow($actor, $supplierId, lock: true);
                if ($supplier->lifecycle_state !== 'ACTIVE') {
                    $this->conflict('Dostawca jest już archiwalny.');
                }
                DB::table('organization_suppliers')->where('id', $supplierId)->update([
                    'lifecycle_state' => 'ARCHIVED',
                    'updated_at' => now(),
                ]);
                $this->commercial->auditAdministrationSupplierChange(
                    $actor,
                    $context,
                    $operationId,
                    'SUPPLIER',
                    $supplierId,
                    'SUPPLIER_ARCHIVED',
                    $this->supplierAuditState($supplier),
                    ['id' => $supplierId, 'lifecycle_state' => 'ARCHIVED'],
                );

                return ['data' => ['id' => $supplierId, 'lifecycle_state' => 'ARCHIVED']];
            },
        );
    }

    public function restore(
        User $actor,
        Location $context,
        string $supplierId,
        string $idempotencyKey,
    ): array {
        return $this->commercial->runAdministrationSupplierMutation(
            $actor,
            $context,
            'administration.supplier.restore',
            $idempotencyKey,
            ['supplier_id' => $supplierId],
            function (string $operationId) use ($actor, $context, $supplierId): array {
                $supplier = $this->supplierRow($actor, $supplierId, lock: true);
                if ($supplier->lifecycle_state !== 'ARCHIVED') {
                    $this->conflict('Dostawca jest już aktywny.');
                }
                $this->contexts->runForSubject(
                    AuthorizationMutationScope::administrationSupplierRestore(),
                    $actor->id,
                    $supplierId,
                    fn (): int => DB::table('organization_suppliers')
                        ->where('organization_id', $actor->organization_id)
                        ->where('id', $supplierId)
                        ->update(['lifecycle_state' => 'ACTIVE', 'updated_at' => now()]),
                );
                $this->commercial->auditAdministrationSupplierChange(
                    $actor,
                    $context,
                    $operationId,
                    'SUPPLIER',
                    $supplierId,
                    'SUPPLIER_RESTORED',
                    $this->supplierAuditState($supplier),
                    ['id' => $supplierId, 'lifecycle_state' => 'ACTIVE'],
                );

                return ['data' => ['id' => $supplierId, 'lifecycle_state' => 'ACTIVE']];
            },
        );
    }

    public function createContact(
        User $actor,
        Location $context,
        string $supplierId,
        array $input,
        string $idempotencyKey,
    ): array {
        return $this->commercial->runAdministrationSupplierMutation(
            $actor,
            $context,
            'administration.supplier.contact.create',
            $idempotencyKey,
            ['supplier_id' => $supplierId, ...$input],
            function (string $operationId) use ($actor, $context, $supplierId, $input): array {
                $this->activeSupplierRow($actor, $supplierId, lock: true);
                $id = Str::uuid()->toString();
                if ($input['is_primary'] ?? false) {
                    $this->clearPrimaryContact($actor, $context, $operationId, $supplierId);
                }
                DB::table('supplier_contacts')->insert([
                    'id' => $id,
                    'organization_id' => $actor->organization_id,
                    'supplier_id' => $supplierId,
                    'full_name' => trim($input['full_name']),
                    'position' => $this->nullableText($input['position'] ?? null),
                    'phone' => $this->nullableText($input['phone'] ?? null),
                    'email' => $this->nullableText($input['email'] ?? null),
                    'is_primary' => (bool) ($input['is_primary'] ?? false),
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
                $this->auditContact(
                    $actor,
                    $context,
                    $operationId,
                    $id,
                    'SUPPLIER_CONTACT_CREATED',
                    null,
                    ['id' => $id, 'supplier_id' => $supplierId, 'is_primary' => (bool) ($input['is_primary'] ?? false)],
                );

                return ['data' => ['id' => $id]];
            },
        );
    }

    public function updateContact(
        User $actor,
        Location $context,
        string $supplierId,
        string $contactId,
        array $input,
        string $idempotencyKey,
    ): array {
        return $this->commercial->runAdministrationSupplierMutation(
            $actor,
            $context,
            'administration.supplier.contact.update',
            $idempotencyKey,
            ['supplier_id' => $supplierId, 'contact_id' => $contactId, ...$input],
            function (string $operationId) use ($actor, $context, $supplierId, $contactId, $input): array {
                $this->activeSupplierRow($actor, $supplierId, lock: true);
                $contact = $this->contactRow($actor, $supplierId, $contactId, lock: true);
                $updates = [];
                $changedFields = [];
                foreach (['full_name', 'position', 'phone', 'email', 'is_primary'] as $field) {
                    if (! array_key_exists($field, $input)) {
                        continue;
                    }
                    $value = $field === 'full_name'
                        ? trim($input[$field])
                        : ($field === 'is_primary' ? (bool) $input[$field] : $this->nullableText($input[$field]));
                    $current = $field === 'is_primary' ? (bool) $contact->{$field} : $contact->{$field};
                    if ($value !== $current) {
                        $updates[$field] = $value;
                        $changedFields[] = $field;
                    }
                }
                if ($updates === []) {
                    throw ValidationException::withMessages([
                        'request' => ['Wprowadź zmianę danych kontaktu.'],
                    ]);
                }
                if ($updates['is_primary'] ?? false) {
                    $this->clearPrimaryContact($actor, $context, $operationId, $supplierId, $contactId);
                }
                $updates['updated_by'] = $actor->id;
                $updates['updated_at'] = now();
                DB::table('supplier_contacts')->where('organization_id', $actor->organization_id)
                    ->where('supplier_id', $supplierId)->where('id', $contactId)->update($updates);
                $this->auditContact(
                    $actor,
                    $context,
                    $operationId,
                    $contactId,
                    'SUPPLIER_CONTACT_CORRECTED',
                    $this->contactAuditState($contact),
                    ['id' => $contactId, 'supplier_id' => $supplierId, 'is_primary' => $updates['is_primary'] ?? (bool) $contact->is_primary],
                    ['changed_fields' => $changedFields],
                );

                return ['data' => ['id' => $contactId]];
            },
        );
    }

    public function removeContact(
        User $actor,
        Location $context,
        string $supplierId,
        string $contactId,
        string $idempotencyKey,
    ): array {
        return $this->commercial->runAdministrationSupplierMutation(
            $actor,
            $context,
            'administration.supplier.contact.remove',
            $idempotencyKey,
            ['supplier_id' => $supplierId, 'contact_id' => $contactId],
            function (string $operationId) use ($actor, $context, $supplierId, $contactId): array {
                $this->activeSupplierRow($actor, $supplierId, lock: true);
                $contact = $this->contactRow($actor, $supplierId, $contactId, lock: true);
                DB::table('supplier_contacts')->where('organization_id', $actor->organization_id)
                    ->where('supplier_id', $supplierId)->where('id', $contactId)->update([
                        'is_primary' => false,
                        'lifecycle_state' => 'REMOVED',
                        'removed_by' => $actor->id,
                        'removed_at' => now(),
                        'updated_by' => $actor->id,
                        'updated_at' => now(),
                    ]);
                $this->auditContact(
                    $actor,
                    $context,
                    $operationId,
                    $contactId,
                    'SUPPLIER_CONTACT_REMOVED',
                    $this->contactAuditState($contact),
                    ['id' => $contactId, 'supplier_id' => $supplierId, 'lifecycle_state' => 'REMOVED'],
                );

                return ['data' => ['id' => $contactId]];
            },
        );
    }

    private function supplierRow(User $actor, string $supplierId, bool $lock = false): object
    {
        $query = DB::table('organization_suppliers')
            ->where('organization_id', $actor->organization_id)
            ->where('id', $supplierId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $supplier = $query->first();
        if (! $supplier) {
            abort(404, __('messages.resource_not_found'));
        }

        return $supplier;
    }

    private function activeSupplierRow(User $actor, string $supplierId, bool $lock = false): object
    {
        $supplier = $this->supplierRow($actor, $supplierId, $lock);
        if ($supplier->lifecycle_state !== 'ACTIVE') {
            $this->conflict('Dostawca jest archiwalny. Przywróć go przed zmianą kontaktów.');
        }

        return $supplier;
    }

    private function presentSupplier(object $supplier, string $organizationId): array
    {
        $contacts = DB::table('supplier_contacts')
            ->where('organization_id', $organizationId)
            ->where('supplier_id', $supplier->id)
            ->where('lifecycle_state', 'ACTIVE')
            ->orderByDesc('is_primary')
            ->orderBy('full_name')
            ->orderBy('id')
            ->get(['id', 'full_name', 'position', 'phone', 'email', 'is_primary'])
            ->map(fn (object $contact): array => [
                'id' => $contact->id,
                'full_name' => $contact->full_name,
                'position' => $contact->position,
                'phone' => $contact->phone,
                'email' => $contact->email,
                'is_primary' => (bool) $contact->is_primary,
            ])->all();

        return [
            'id' => $supplier->id,
            'display_name' => $supplier->display_name,
            'legal_name' => $supplier->legal_name ?? $supplier->display_name,
            'tax_identifier' => $this->decrypt($supplier->tax_identifier_ciphertext),
            'street_address' => $supplier->street_address,
            'city' => $supplier->city,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'website' => $supplier->website,
            'lifecycle_state' => $supplier->lifecycle_state,
            'raw_material_count' => $this->materialCounts($organizationId, [$supplier->id])[$supplier->id] ?? 0,
            'contacts' => $contacts,
        ];
    }

    private function contactRow(User $actor, string $supplierId, string $contactId, bool $lock = false): object
    {
        $query = DB::table('supplier_contacts')
            ->where('organization_id', $actor->organization_id)
            ->where('supplier_id', $supplierId)
            ->where('id', $contactId)
            ->where('lifecycle_state', 'ACTIVE');
        if ($lock) {
            $query->lockForUpdate();
        }
        $contact = $query->first();
        if (! $contact) {
            abort(404, __('messages.resource_not_found'));
        }

        return $contact;
    }

    private function clearPrimaryContact(
        User $actor,
        Location $context,
        string $operationId,
        string $supplierId,
        ?string $exceptContactId = null,
    ): void {
        $query = DB::table('supplier_contacts')
            ->where('organization_id', $actor->organization_id)
            ->where('supplier_id', $supplierId)
            ->where('lifecycle_state', 'ACTIVE')
            ->where('is_primary', true);
        if ($exceptContactId !== null) {
            $query->where('id', '<>', $exceptContactId);
        }

        foreach ($query->lockForUpdate()->get() as $contact) {
            DB::table('supplier_contacts')->where('id', $contact->id)->update([
                'is_primary' => false,
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ]);
            $this->auditContact(
                $actor,
                $context,
                $operationId,
                $contact->id,
                'SUPPLIER_CONTACT_PRIMARY_CLEARED',
                $this->contactAuditState($contact),
                ['id' => $contact->id, 'supplier_id' => $supplierId, 'is_primary' => false],
                ['changed_fields' => ['is_primary']],
            );
        }
    }

    private function auditContact(
        User $actor,
        Location $context,
        string $operationId,
        string $contactId,
        string $eventCode,
        mixed $before,
        mixed $after,
        array $additionalMetadata = [],
    ): void {
        $this->commercial->auditAdministrationSupplierChange(
            $actor,
            $context,
            $operationId,
            'SUPPLIER_CONTACT',
            $contactId,
            $eventCode,
            $before,
            $after,
            $additionalMetadata,
        );
    }

    private function supplierAuditState(object $supplier): array
    {
        return [
            'id' => $supplier->id,
            'display_name' => $supplier->display_name,
            'legal_name' => $supplier->legal_name,
            'tax_identifier_fingerprint' => $supplier->tax_identifier_fingerprint,
            'street_address' => $supplier->street_address,
            'city' => $supplier->city,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'website' => $supplier->website,
            'lifecycle_state' => $supplier->lifecycle_state,
        ];
    }

    private function contactAuditState(object $contact): array
    {
        return [
            'id' => $contact->id,
            'supplier_id' => $contact->supplier_id,
            'full_name' => $contact->full_name,
            'position' => $contact->position,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'is_primary' => (bool) $contact->is_primary,
            'lifecycle_state' => $contact->lifecycle_state,
        ];
    }

    private function materialCounts(string $organizationId, array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }

        $references = DB::table('raw_material_supplier_associations')
            ->select(['organization_id', 'supplier_id', 'raw_material_id'])
            ->where('organization_id', $organizationId)
            ->whereIn('supplier_id', $supplierIds)
            ->unionAll(DB::table('supplier_products')
                ->select(['organization_id', 'supplier_id', 'raw_material_id'])
                ->where('organization_id', $organizationId)
                ->whereIn('supplier_id', $supplierIds));

        return DB::query()->fromSub($references, 'supplier_material_references')
            ->select('supplier_id')
            ->selectRaw('count(distinct raw_material_id) as raw_material_count')
            ->groupBy('supplier_id')
            ->pluck('raw_material_count', 'supplier_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    private function assertTaxIdentifierAvailable(
        string $organizationId,
        string $taxIdentifier,
        ?string $exceptSupplierId = null,
    ): void {
        $fingerprint = SupplierTaxIdentifier::fingerprint($taxIdentifier);
        $query = DB::table('organization_suppliers')
            ->where('organization_id', $organizationId)
            ->where('tax_identifier_fingerprint', $fingerprint);
        if ($exceptSupplierId !== null) {
            $query->where('id', '<>', $exceptSupplierId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'tax_identifier' => ['Dostawca z tym identyfikatorem podatkowym już istnieje.'],
            ]);
        }
    }

    private function nullableText(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function decrypt(?string $ciphertext): ?string
    {
        return $ciphertext === null
            ? null
            : SupplierTaxIdentifier::normalize(Crypt::decryptString($ciphertext));
    }

    private function normalizeCompanyName(string $value): string
    {
        $ascii = Str::ascii($value);
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '');

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    private function conflict(string $message): never
    {
        throw new RawMaterialException('RAW_MATERIAL_SUPPLIER_STATE_INVALID', $message, 409);
    }
}
