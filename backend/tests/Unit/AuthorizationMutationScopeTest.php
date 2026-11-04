<?php

namespace Tests\Unit;

use App\Domain\Authorization\AuthorizationMutationScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AuthorizationMutationScopeTest extends TestCase
{
    public function test_security_mutation_scopes_are_selected_from_a_closed_catalog(): void
    {
        $this->assertSame('membership.deactivate', AuthorizationMutationScope::membershipDeactivate()->value());

        foreach ([
            'personnel.request',
            'membership.approve',
            'membership.reject',
            'membership.assign',
            'membership.remove',
            'report.share',
            'report.share_revoke',
            'report.export_authorize.REPORTS_EXPORT',
            'account.deactivate',
            'account.reactivate',
            'account.email_change',
            'structure.create',
            'structure.update',
            'structure.management',
            'role.grant.ADMINISTRATOR',
            'role.revoke.ADMINISTRATOR',
            'permission.grant.RAW_MATERIALS_VIEW',
            'permission.revoke.RAW_MATERIALS_VIEW',
        ] as $action) {
            $expectedScope = match ($action) {
                'structure.create' => 'installation.location.create',
                'structure.update' => 'installation.location.update',
                'structure.management' => 'installation.management.assign',
                default => $action,
            };
            $this->assertSame($expectedScope, AuthorizationMutationScope::forSecurityAction($action)->value());
        }

        $this->expectException(InvalidArgumentException::class);
        AuthorizationMutationScope::forSecurityAction('role.grant.UNRECOGNIZED');
    }
}
