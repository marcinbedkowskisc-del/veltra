<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\PermissionAdministration;
use App\Domain\Authorization\PersonnelAdministration;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Authorization\SecurityMutation;
use App\Domain\Authorization\SuperAdminProfileAdministration;
use App\Domain\Identity\AccountLinks;
use App\Domain\Installation\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\SecurityRequest;
use App\Http\Resources\UserResource;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\MembershipRequest;
use App\Models\PermissionGrant;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    public function grantRole(SecurityRequest $request, User $user, RoleAdministration $service): JsonResponse
    {
        $role = BusinessRole::from($request->validated('role'));
        $location = $request->validated('location_id') ? Location::findOrFail($request->validated('location_id')) : null;
        $assignment = $service->grant($request->user(), $request->attributes->get('operating_location'), $user, $role, $location, $request->validated('current_password'));

        return response()->json(['data' => ['id' => $assignment->id, 'role' => $assignment->role, 'location_id' => $assignment->location_id]], 201);
    }

    public function revokeRole(SecurityRequest $request, RoleAssignment $assignment, RoleAdministration $service): JsonResponse
    {
        $service->revoke($request->user(), $request->attributes->get('operating_location'), $assignment, $request->validated('current_password'));

        return response()->json(['data' => null]);
    }

    public function accountStatus(SecurityRequest $request, User $user, RoleAdministration $service): JsonResponse
    {
        $service->setAccountActive($request->user(), $request->attributes->get('operating_location'), $user, $request->boolean('is_active'));

        return response()->json(['data' => null]);
    }

    public function changeEmail(SecurityRequest $request, User $user, RoleAdministration $service): JsonResponse
    {
        $service->changeEmail($request->user(), $request->attributes->get('operating_location'), $user, $request->validated('email'));
        $this->requestLink($request, $user->refresh(), 'activation');

        return $this->accepted();
    }

    public function updateMyProfile(SecurityRequest $request, SuperAdminProfileAdministration $service): JsonResponse
    {
        $actor = $request->user();
        $emailChanged = $service->update(
            $actor,
            $request->attributes->get('operating_location'),
            $request->safe()->only(['first_name', 'last_name', 'email']),
            $request->validated('current_password'),
        );
        if ($emailChanged) {
            $this->requestLink($request, $actor->refresh(), 'activation', true);
        }

        return $this->accepted();
    }

    public function users(Request $request, Location $location, PersonnelAdministration $service): JsonResponse
    {
        $users = $service->scopedUsers($request->user(), $request->attributes->get('operating_location'), $location)
            ->orderBy('id')->paginate(25);

        return response()->json(['data' => UserResource::collection($users), 'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage()]]);
    }

    public function personnel(SecurityRequest $request, Location $location, PersonnelAdministration $service): JsonResponse
    {
        $new = $service->requestPersonnel($request->user(), $request->attributes->get('operating_location'), $location, $request->validated());
        if ($new) {
            $this->requestLink($request, $new, 'activation');
        }

        return $this->accepted();
    }

    public function requests(Request $request, Location $location, BusinessAuthorization $authorization): JsonResponse
    {
        $actor = $request->user();
        $context = $request->attributes->get('operating_location');
        abort_unless($authorization->eligible($actor, $context, $location)
            && ($authorization->hasRole($actor, BusinessRole::Administrator) || $authorization->manages($actor, $context, $location)), 403);
        $items = MembershipRequest::where('location_id', $location->id)->whereNull('decision')->orderBy('requested_at')->paginate(25);

        return response()->json(['data' => $items->items(), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage()]]);
    }

    public function decide(SecurityRequest $request, MembershipRequest $membershipRequest, PersonnelAdministration $service): JsonResponse
    {
        $service->decide($request->user(), $request->attributes->get('operating_location'), $membershipRequest, $request->boolean('approve'));

        return response()->json(['data' => null]);
    }

    public function removeMembership(SecurityRequest $request, LocationMembership $membership, PersonnelAdministration $service): JsonResponse
    {
        $service->removeMembership($request->user(), $request->attributes->get('operating_location'), $membership);

        return response()->json(['data' => null]);
    }

    public function assignMembership(SecurityRequest $request, User $user, Location $location, PersonnelAdministration $service): JsonResponse
    {
        $membership = $service->assignMembership($request->user(), $request->attributes->get('operating_location'), $user, $location);

        return response()->json(['data' => ['id' => $membership->id]], 201);
    }

    public function grantPermission(SecurityRequest $request, User $user, Location $location, PermissionAdministration $service): JsonResponse
    {
        $grant = $service->grant($request->user(), $request->attributes->get('operating_location'), $user, $location,
            Permission::from($request->validated('permission')),
            $request->validated('valid_from') ? CarbonImmutable::parse($request->validated('valid_from')) : null,
            $request->validated('valid_until') ? CarbonImmutable::parse($request->validated('valid_until')) : null);

        return response()->json(['data' => ['id' => $grant->id, 'permission' => $grant->permission]], 201);
    }

    public function revokePermission(SecurityRequest $request, User $user, Location $location, PermissionAdministration $service): JsonResponse
    {
        $service->revokeEffective($request->user(), $request->attributes->get('operating_location'), $user, $location, Permission::from($request->validated('permission')));

        return response()->json(['data' => null]);
    }

    public function access(SecurityRequest $request, BusinessAuthorization $authorization): JsonResponse
    {
        $location = Location::find($request->validated('location_id'));
        $allowed = $location && $authorization->allows($request->user(), $request->attributes->get('operating_location'), $location, Permission::from($request->validated('permission')));

        return response()->json(['data' => ['allowed' => (bool) $allowed]]);
    }

    public function assignments(SecurityRequest $request, User $user, BusinessAuthorization $authorization, PersonnelAdministration $personnel): JsonResponse
    {
        $context = $request->attributes->get('operating_location');
        $location = $request->validated('location_id') ? Location::findOrFail($request->validated('location_id')) : $context;
        abort_unless($user->organization_id === $request->user()->organization_id && $authorization->eligible($request->user(), $context, $location), 403);
        $administrator = $authorization->hasRole($request->user(), BusinessRole::Administrator);
        if (! $administrator && ! $user->is($request->user())) {
            abort_unless($personnel->scopedUsers($request->user(), $context, $location)->whereKey($user->id)->exists(), 403);
        }
        $roles = RoleAssignment::where('user_id', $user->id);
        if (! $administrator) {
            $roles->where(function ($scope) use ($location, $user, $request): void {
                $scope->where('location_id', $location->id);
                if ($user->is($request->user())) {
                    $scope->orWhereNull('location_id');
                }
            });
        }
        $grants = PermissionGrant::where('user_id', $user->id)->where('location_id', $location->id)->orderBy('valid_from')->limit(100)->get();

        return response()->json(['data' => ['roles' => $roles->orderBy('granted_at')->limit(100)->get(), 'permissions' => $grants]]);
    }

    public function link(SecurityRequest $request, string $identifier): JsonResponse
    {
        $target = User::find($identifier);
        if ($target) {
            $this->requestLink($request, $target, $request->validated('kind'));
        } else {
            app(SecurityMutation::class)->record($request->user(), $request->user(), $request->attributes->get('operating_location'), 'email.request_unknown', 'rejected');
        }

        return $this->accepted();
    }

    private function requestLink(Request $request, User $target, string $kind, bool $selfProfileVerification = false): void
    {
        try {
            app(AccountLinks::class)->issue(
                $target,
                $kind,
                $request->ip(),
                $request->user(),
                $request->attributes->get('operating_location'),
                selfProfileVerification: $selfProfileVerification,
            );
        } catch (AuthorizationException|BusinessRuleViolation) {
            app(SecurityMutation::class)->record($request->user(), $target, $request->attributes->get('operating_location'), 'email.request_unavailable', 'rejected');
        }
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['data' => null, 'message' => __('messages.request_received')], 202);
    }
}
