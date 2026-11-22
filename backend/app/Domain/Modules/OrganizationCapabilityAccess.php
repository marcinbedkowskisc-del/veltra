<?php

namespace App\Domain\Modules;

use App\Domain\Installation\LocationType;
use Illuminate\Support\Facades\DB;

final class OrganizationCapabilityAccess
{
    public function __construct(private readonly OrganizationCapabilityCatalog $catalog) {}

    public function isEnabled(
        string $organizationId,
        string $key,
        string $operation,
        LocationType $operatingContextType,
    ): bool {
        $definition = $this->catalog->find($key);
        if (! $definition
            || $definition->scope !== 'ORGANIZATION'
            || ! $definition->ready
            || ! in_array($operation, $definition->operations, true)
            || ! in_array($operatingContextType, $definition->operatingContextTypes, true)) {
            return false;
        }

        $state = DB::table('organization_capability_states')
            ->where('organization_id', $organizationId)
            ->where('capability_key', $key)
            ->first(['enabled']);

        return $state !== null && in_array($state->enabled, [true, 1, '1', 't', 'true'], true);
    }
}
