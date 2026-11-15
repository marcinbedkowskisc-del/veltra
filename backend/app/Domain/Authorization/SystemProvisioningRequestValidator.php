<?php

namespace App\Domain\Authorization;

use App\Domain\Modules\DefinedModuleCatalog;
use App\Domain\RawMaterials\RawMaterialLocationSharePolicy;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class SystemProvisioningRequestValidator
{
    public function __construct(private BusinessAuthorization $authorization) {}

    /**
     * Validates only the request; it does not authenticate an operator or authorize a grant.
     * A future operation must revalidate under its transaction locks.
     * Checking the selected location does not make organization-wide grants location-scoped.
     *
     * @param  list<string>  $permissions
     */
    public function validate(
        Organization $organization,
        User $administrator,
        Location $location,
        string $module,
        array $permissions,
    ): void {
        validator(['permissions' => $permissions], [
            'permissions' => ['required', 'array', 'list', 'min:1'],
            'permissions.*' => ['required', 'string', 'distinct:strict'],
        ])->validate();

        $definition = (new DefinedModuleCatalog)->find($module);
        if ($definition === null || $module !== 'RAW_MATERIALS') {
            throw ValidationException::withMessages(['module' => [__('messages.system_provisioning_module_invalid')]]);
        }
        foreach ($permissions as $permission) {
            if (RawMaterialPermission::tryFrom($permission) === null) {
                throw ValidationException::withMessages(['permissions' => [__('messages.system_provisioning_permission_invalid')]]);
            }
        }

        $organization = $organization->fresh();
        $administrator = $administrator->fresh();
        $location = $location->fresh();
        if (! $organization || ! $administrator || ! $location
            || ! $organization->is_active || ! $organization->authorization_initialized
            || $administrator->organization_id !== $organization->id
            || $location->organization_id !== $organization->id
            || ! $definition->supports($location->type)
            || ! $this->authorization->eligible($administrator, $location)
            || ! $this->authorization->hasRole($administrator, BusinessRole::Administrator)) {
            throw new AuthorizationException(__('messages.system_provisioning_target_invalid'));
        }
        foreach ($permissions as $permission) {
            if (RawMaterialPermission::from($permission)->requiresPrimaryCatalogContext()
                && ! app(RawMaterialLocationSharePolicy::class)->isPrimaryContext($location)) {
                throw new AuthorizationException(__('messages.system_provisioning_target_invalid'));
            }
        }
    }
}
