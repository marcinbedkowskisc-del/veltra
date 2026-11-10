<?php

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ModuleCatalogFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'veltra_testing' || DB::scalar('SELECT current_user') !== 'veltra_app') {
    throw new RuntimeException('Fixture wymaga izolowanej bazy i ograniczonej roli.');
}
[$script, $actorId, $locationId, $materialId, $version, $operation, $payload, $application] = $argv;
if (! preg_match('/^[a-z0-9-]{1,63}$/D', $application)) {
    throw new InvalidArgumentException('Nieprawidłowa nazwa procesu testowego.');
}
DB::select('SELECT set_config(?, ?, false)', ['application_name', $application]);
$app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
$documentRoot = getenv('TEST_RAW_DOCUMENT_ROOT');
if ($documentRoot !== false) {
    if (! str_starts_with($documentRoot, storage_path('framework/testing/'))) {
        throw new RuntimeException('Fixture dokumentow wymaga magazynu w izolowanym katalogu testowym.');
    }
    config(['filesystems.disks.raw_material_documents.root' => $documentRoot]);
}
$actor = User::findOrFail($actorId);
$context = Location::findOrFail($locationId);
$service = app(RawMaterialService::class);
$decodedPayload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
try {
    $result = match ($operation) {
        'revise' => $service->revise($actor, $context, $materialId, $decodedPayload, $version),
        'submit' => $service->submit($actor, $context, $materialId, $version),
        'archive' => $service->archive($actor, $context, $materialId, 'Test wyścigu', $version),
        'bulk-archive' => $service->archiveBulk(
            $actor,
            $context,
            $decodedPayload['items'],
            $decodedPayload['reason'],
        ),
        'approve' => $service->approve($actor, $context, $materialId, 'Test wyścigu', $version),
        'return' => $service->returnForChanges($actor, $context, $materialId, 'Test wyścigu', $version),
        'delete-draft' => $service->deleteDraft($actor, $context, $materialId, $version),
        'commercial-dependency' => app(AuthorizationMutationContext::class)->runForSubject(
            AuthorizationMutationScope::rawMaterialCommercial(),
            $actor->id,
            $materialId,
            function () use ($actor, $context, $materialId): void {
                DB::transaction(function () use ($actor, $context, $materialId): void {
                    Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
                    DB::table('raw_materials_commercial_audit_events')->insert([
                        'id' => Str::uuid()->toString(),
                        'organization_id' => $actor->organization_id,
                        'operation_id' => Str::uuid()->toString(),
                        'actor_user_id' => $actor->id,
                        'actor_location_id' => $context->id,
                        'subject_type' => 'RAW_MATERIAL',
                        'subject_id' => $materialId,
                        'event_code' => 'TEST_DEPENDENCY_CREATED',
                        'outcome' => 'ACCEPTED',
                        'metadata' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
                    ]);
                });
            },
        ),
        default => throw new InvalidArgumentException('Nieprawidłowa operacja testowa.'),
    };
    $result = ['status' => $operation === 'delete-draft' ? 204 : 200, 'data' => $result];
} catch (RawMaterialException $exception) {
    $result = ['status' => $exception->status, 'code' => $exception->errorCode];
} catch (QueryException $exception) {
    $result = ['status' => 409, 'code' => 'DATABASE_CONFLICT'];
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
