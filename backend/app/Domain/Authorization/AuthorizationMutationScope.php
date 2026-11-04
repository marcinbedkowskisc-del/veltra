<?php

namespace App\Domain\Authorization;

use App\Domain\RawMaterials\RawMaterialPermission;
use InvalidArgumentException;

final readonly class AuthorizationMutationScope
{
    private function __construct(private string $value) {}

    public static function accountIssue(): self
    {
        return new self('account.issue');
    }

    public static function accountConsume(): self
    {
        return new self('account.consume');
    }

    public static function accountDeactivate(): self
    {
        return new self('account.deactivate');
    }

    public static function accountReactivate(): self
    {
        return new self('account.reactivate');
    }

    public static function accountEmailChange(): self
    {
        return new self('account.email_change');
    }

    public static function administratorBootstrap(): self
    {
        return new self('administrator.bootstrap');
    }

    public static function identityBootstrap(): self
    {
        return new self('identity.bootstrap');
    }

    public static function identityDisable(): self
    {
        return new self('identity.disable');
    }

    public static function installationInitialize(): self
    {
        return new self('installation.initialize');
    }

    public static function installationLocationCreate(): self
    {
        return new self('installation.location.create');
    }

    public static function installationLocationUpdate(): self
    {
        return new self('installation.location.update');
    }

    public static function installationManagementAssign(): self
    {
        return new self('installation.management.assign');
    }

    public static function installationSupplyEstablish(): self
    {
        return new self('installation.supply.establish');
    }

    public static function installationSupplyDisable(): self
    {
        return new self('installation.supply.disable');
    }

    public static function membershipAssign(): self
    {
        return new self('membership.assign');
    }

    public static function membershipDeactivate(): self
    {
        return new self('membership.deactivate');
    }

    public static function personnelRequest(): self
    {
        return new self('personnel.request');
    }

    public static function personnelMembershipApprove(): self
    {
        return new self('membership.approve');
    }

    public static function personnelMembershipReject(): self
    {
        return new self('membership.reject');
    }

    public static function personnelMembershipRemove(): self
    {
        return new self('membership.remove');
    }

    public static function moduleConfigure(): self
    {
        return new self('module.configure');
    }

    public static function permissionBootstrap(): self
    {
        return new self('permission.bootstrap');
    }

    public static function permissionUpgrade(): self
    {
        return new self('permission.upgrade');
    }

    public static function permissionGrant(Permission|RawMaterialPermission $permission): self
    {
        return new self('permission.grant.'.$permission->value);
    }

    public static function permissionRevoke(Permission|RawMaterialPermission $permission): self
    {
        return new self('permission.revoke.'.$permission->value);
    }

    public static function roleGrant(BusinessRole $role): self
    {
        return new self('role.grant.'.$role->value);
    }

    public static function roleRevoke(BusinessRole $role): self
    {
        return new self('role.revoke.'.$role->value);
    }

    public static function reportShare(): self
    {
        return new self('report.share');
    }

    public static function reportShareRevoke(): self
    {
        return new self('report.share_revoke');
    }

    public static function reportExportAuthorize(Permission $permission): self
    {
        return new self('report.export_authorize.'.$permission->value);
    }

    public static function rawMaterialMutate(): self
    {
        return new self('raw-material.mutate');
    }

    public static function rawMaterialUnblock(): self
    {
        return new self('raw-material.unblock');
    }

    public static function rawMaterialCommercial(): self
    {
        return new self('raw-material.commercial');
    }

    public static function securityEvent(): self
    {
        return new self('security.event');
    }

    public static function moduleAudit(): self
    {
        return new self('module.audit');
    }

    public static function forSecurityAction(string $action): self
    {
        $fixed = [
            'personnel.request' => self::personnelRequest(...),
            'membership.approve' => self::personnelMembershipApprove(...),
            'membership.reject' => self::personnelMembershipReject(...),
            'membership.assign' => self::membershipAssign(...),
            'membership.remove' => self::personnelMembershipRemove(...),
            'report.share' => self::reportShare(...),
            'report.share_revoke' => self::reportShareRevoke(...),
            'account.deactivate' => self::accountDeactivate(...),
            'account.reactivate' => self::accountReactivate(...),
            'account.email_change' => self::accountEmailChange(...),
            'structure.create' => self::installationLocationCreate(...),
            'structure.update' => self::installationLocationUpdate(...),
            'structure.management' => self::installationManagementAssign(...),
        ];
        if (isset($fixed[$action])) {
            return $fixed[$action]();
        }

        foreach (BusinessRole::cases() as $role) {
            if ($action === 'role.grant.'.$role->value) {
                return self::roleGrant($role);
            }
            if ($action === 'role.revoke.'.$role->value) {
                return self::roleRevoke($role);
            }
        }

        foreach ([...Permission::cases(), ...RawMaterialPermission::cases()] as $permission) {
            if ($action === 'permission.grant.'.$permission->value) {
                return self::permissionGrant($permission);
            }
            if ($action === 'permission.revoke.'.$permission->value) {
                return self::permissionRevoke($permission);
            }
        }

        if (str_starts_with($action, 'report.export_authorize.')) {
            $permission = Permission::tryFrom(substr($action, strlen('report.export_authorize.')));
            if ($permission) {
                return self::reportExportAuthorize($permission);
            }
        }

        throw new InvalidArgumentException('Unsupported authorization mutation action.');
    }

    public function value(): string
    {
        return $this->value;
    }
}
