<?php

namespace App\Domain\Authorization;

use App\Domain\Identity\UserAvatarType;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AdministrationUserService
{
    public function __construct(
        private BusinessAuthorization $authorization,
        private SecurityMutation $mutations,
    ) {}

    public function list(User $actor, Location $context, array $filters): array
    {
        $this->authorizeAdministrator($actor, $context);

        $query = User::query()->where('organization_id', $actor->organization_id);
        $status = $filters['status'] ?? 'active';
        if ($status !== 'all') {
            $query->where('is_active', $status === 'active');
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(fn (Builder $where) => $where
                ->whereRaw("first_name || ' ' || last_name ILIKE ?", [$term])
                ->orWhereRaw('email ILIKE ?', [$term]));
        }

        if (! empty($filters['role'])) {
            $query->whereExists(function ($roles) use ($filters): void {
                $roles->selectRaw('1')->from('role_assignments as assignments')
                    ->whereColumn('assignments.user_id', 'users.id')
                    ->whereColumn('assignments.organization_id', 'users.organization_id')
                    ->where('assignments.role', $filters['role'])
                    ->whereNull('assignments.revoked_at')
                    ->where(function ($scope): void {
                        $scope->whereNull('assignments.membership_id')->orWhereExists(function ($memberships): void {
                            $memberships->selectRaw('1')->from('location_memberships as role_memberships')
                                ->join('locations as role_locations', 'role_locations.id', '=', 'role_memberships.location_id')
                                ->whereColumn('role_memberships.id', 'assignments.membership_id')
                                ->where('role_memberships.is_active', true)
                                ->where('role_locations.is_active', true);
                        });
                    });
            });
        }

        if (! empty($filters['location_id'])) {
            $query->whereExists(function ($memberships) use ($filters): void {
                $memberships->selectRaw('1')->from('location_memberships')
                    ->whereColumn('location_memberships.user_id', 'users.id')
                    ->whereColumn('location_memberships.organization_id', 'users.organization_id')
                    ->where('location_memberships.location_id', $filters['location_id'])
                    ->where('location_memberships.is_active', true);
            });
        }

        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        match ($filters['sort'] ?? 'name') {
            'email' => $query->orderBy('email', $direction),
            'status' => $query->orderBy('is_active', $direction)->orderBy('first_name')->orderBy('last_name'),
            default => $query->orderBy('first_name', $direction)->orderBy('last_name', $direction),
        };

        $page = $query->paginate((int) ($filters['per_page'] ?? 25), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $users = $page->getCollection();
        $userIds = $users->modelKeys();

        $roles = RoleAssignment::effective()->whereIn('user_id', $userIds)->get([
            'id', 'user_id', 'role', 'location_id',
        ]);
        $memberships = DB::table('location_memberships as memberships')
            ->join('locations', 'locations.id', '=', 'memberships.location_id')
            ->whereIn('memberships.user_id', $userIds)
            ->where('memberships.organization_id', $actor->organization_id)
            ->where('memberships.is_active', true)
            ->where('locations.is_active', true)
            ->get([
                'memberships.user_id', 'locations.id as location_id', 'locations.name as location_name',
                'locations.type as location_type',
            ]);
        $locationIds = $roles->pluck('location_id')->filter()->merge($memberships->pluck('location_id'))->unique();
        $locationNames = Location::whereIn('id', $locationIds)->pluck('name', 'id');

        $data = $users->map(function (User $user) use ($roles, $memberships, $locationNames): array {
            return $this->userSummary(
                $user,
                $roles->where('user_id', $user->id)->map(fn (RoleAssignment $role): array => [
                    'role' => $role->role->value,
                    'location_id' => $role->location_id,
                    'location_name' => $role->location_id ? $locationNames->get($role->location_id) : null,
                ])->values()->all(),
                $memberships->where('user_id', $user->id)->map(fn (object $membership): array => [
                    'id' => $membership->location_id,
                    'name' => $membership->location_name,
                    'type' => $membership->location_type,
                ])->values()->all(),
            );
        })->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    public function locations(User $actor, Location $context): array
    {
        $this->authorizeAdministrator($actor, $context);

        return Location::where('organization_id', $actor->organization_id)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'type', 'is_active'])
            ->map(fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'type' => $location->type->value,
                'is_active' => $location->is_active,
            ])->all();
    }

    public function show(User $actor, Location $context, string $userId): array
    {
        $this->authorizeAdministrator($actor, $context);
        $user = User::where('organization_id', $actor->organization_id)->whereKey($userId)->firstOrFail();

        $roles = DB::table('role_assignments as assignments')
            ->leftJoin('locations', 'locations.id', '=', 'assignments.location_id')
            ->where('assignments.user_id', $user->id)
            ->where('assignments.organization_id', $actor->organization_id)
            ->orderBy('assignments.granted_at')
            ->get([
                'assignments.id', 'assignments.role', 'assignments.location_id', 'locations.name as location_name',
                'assignments.granted_at', 'assignments.revoked_at',
            ]);
        $memberships = DB::table('location_memberships as memberships')
            ->join('locations', 'locations.id', '=', 'memberships.location_id')
            ->where('memberships.user_id', $user->id)
            ->where('memberships.organization_id', $actor->organization_id)
            ->orderBy('locations.name')
            ->get([
                'memberships.id', 'memberships.location_id', 'locations.name as location_name',
                'locations.type as location_type', 'locations.is_active as location_is_active',
                'memberships.is_active', 'memberships.assigned_at', 'memberships.deactivated_at',
            ]);
        $permissions = DB::table('permission_grants as grants')
            ->join('locations', 'locations.id', '=', 'grants.location_id')
            ->where('grants.user_id', $user->id)
            ->where('grants.organization_id', $actor->organization_id)
            ->orderBy('locations.name')->orderBy('grants.valid_from')
            ->get([
                'grants.id', 'grants.permission', 'grants.location_id', 'locations.name as location_name',
                'grants.valid_from', 'grants.valid_until', 'grants.revoked_at',
            ]);
        $requests = DB::table('membership_requests as requests')
            ->join('locations', 'locations.id', '=', 'requests.location_id')
            ->leftJoin('users as requesters', 'requesters.id', '=', 'requests.requested_by')
            ->leftJoin('users as decision_makers', 'decision_makers.id', '=', 'requests.decided_by')
            ->where('requests.user_id', $user->id)
            ->where('requests.organization_id', $actor->organization_id)
            ->orderByDesc('requests.requested_at')
            ->get([
                'requests.id', 'requests.location_id', 'locations.name as location_name',
                'requests.requested_by', 'requesters.first_name as requester_first_name',
                'requesters.last_name as requester_last_name', 'requests.decision',
                'requests.decided_by', 'decision_makers.first_name as decider_first_name',
                'decision_makers.last_name as decider_last_name', 'requests.requested_at', 'requests.decided_at',
            ]);
        $history = DB::table('security_events as events')
            ->leftJoin('users as actors', 'actors.id', '=', 'events.actor_id')
            ->leftJoin('locations', 'locations.id', '=', 'events.location_id')
            ->where('events.organization_id', $actor->organization_id)
            ->where('events.target_id', $user->id)
            ->orderByDesc('events.occurred_at')->limit(100)
            ->get([
                'events.action', 'events.outcome', 'events.occurred_at',
                'actors.first_name as actor_first_name', 'actors.last_name as actor_last_name',
                'locations.name as location_name',
            ]);

        return [
            'data' => [
                ...$this->userSummary($user, [], []),
                'roles' => $roles->map(fn (object $role): array => [
                    'id' => $role->id,
                    'role' => $role->role,
                    'location_id' => $role->location_id,
                    'location_name' => $role->location_name,
                    'granted_at' => $role->granted_at,
                    'revoked_at' => $role->revoked_at,
                ])->all(),
                'memberships' => $memberships->map(fn (object $membership): array => [
                    'id' => $membership->id,
                    'location_id' => $membership->location_id,
                    'location_name' => $membership->location_name,
                    'location_type' => $membership->location_type,
                    'location_is_active' => $membership->location_is_active,
                    'is_active' => $membership->is_active,
                    'assigned_at' => $membership->assigned_at,
                    'deactivated_at' => $membership->deactivated_at,
                ])->all(),
                'permissions' => $permissions->map(fn (object $grant): array => [
                    'id' => $grant->id,
                    'permission' => $grant->permission,
                    'location_id' => $grant->location_id,
                    'location_name' => $grant->location_name,
                    'valid_from' => $grant->valid_from,
                    'valid_until' => $grant->valid_until,
                    'revoked_at' => $grant->revoked_at,
                ])->all(),
                'requests' => $requests->map(fn (object $request): array => [
                    'id' => $request->id,
                    'location_id' => $request->location_id,
                    'location_name' => $request->location_name,
                    'requester_name' => trim(($request->requester_first_name ?? '').' '.($request->requester_last_name ?? '')),
                    'decision' => $request->decision,
                    'decider_name' => trim(($request->decider_first_name ?? '').' '.($request->decider_last_name ?? '')),
                    'requested_at' => $request->requested_at,
                    'decided_at' => $request->decided_at,
                ])->all(),
                'history' => $history->map(fn (object $event): array => [
                    'action' => $event->action,
                    'outcome' => $event->outcome,
                    'occurred_at' => $event->occurred_at,
                    'actor_name' => trim(($event->actor_first_name ?? 'System').($event->actor_last_name ? ' '.$event->actor_last_name : '')),
                    'location_name' => $event->location_name,
                ])->all(),
                'effective_permissions' => $this->effectivePermissions($user),
            ],
        ];
    }

    private function effectivePermissions(User $user): array
    {
        if (! $user->is_active || $user->email_verified_at === null || ! $user->organization->is_active) {
            return [];
        }

        $contexts = app(MembershipService::class)->available($user)->with('location')->get()
            ->map(fn (LocationMembership $membership): ?Location => $membership->location)
            ->filter()
            ->values();
        $locations = Location::where('organization_id', $user->organization_id)
            ->where('is_active', true)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'type', 'is_active', 'organization_id']);
        $effective = [];

        foreach ($contexts as $context) {
            foreach ($locations as $location) {
                foreach (Permission::cases() as $permission) {
                    if (! $this->authorization->allows($user, $context, $location, $permission)) {
                        continue;
                    }

                    $effective[$location->id]['location_id'] = $location->id;
                    $effective[$location->id]['location_name'] = $location->name;
                    $effective[$location->id]['permissions'][$permission->value]['permission'] = $permission->value;
                    $effective[$location->id]['permissions'][$permission->value]['operating_contexts'][$context->id] = [
                        'id' => $context->id,
                        'name' => $context->name,
                    ];
                }
            }
        }

        return array_values(array_map(
            fn (array $location): array => [
                'location_id' => $location['location_id'],
                'location_name' => $location['location_name'],
                'permissions' => array_values(array_map(
                    fn (array $permission): array => [
                        'permission' => $permission['permission'],
                        'operating_contexts' => array_values($permission['operating_contexts']),
                    ],
                    $location['permissions'],
                )),
            ],
            $effective,
        ));
    }

    public function updateProfile(User $actor, Location $context, string $userId, array $profile): void
    {
        $user = User::where('organization_id', $actor->organization_id)->whereKey($userId)->firstOrFail();
        $this->mutations->run($actor, $user, $context, 'account.profile_update', function () use ($actor, $context, $user, $profile): User {
            $this->authorizeAdministrator($actor, $context);
            if ($actor->organization_id !== $user->organization_id || ! $this->authorization->canManageAccount($actor, $user)) {
                throw new AuthorizationException;
            }

            $user->update([
                'first_name' => trim($profile['first_name']),
                'last_name' => trim($profile['last_name']),
            ]);

            return $user;
        });
    }

    private function authorizeAdministrator(User $actor, Location $context): void
    {
        if (! $this->authorization->eligible($actor, $context)
            || ! $this->authorization->hasRole($actor, BusinessRole::Administrator)) {
            throw new AuthorizationException;
        }
    }

    private function userSummary(User $user, array $roles, array $locations): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'avatar' => [
                'type' => $user->avatar_type->value,
                'library_id' => $user->avatar_library_id,
                'photo_version' => $user->avatar_type === UserAvatarType::Upload
                    ? hash('sha256', (string) $user->avatar_photo_key)
                    : null,
            ],
            'email_verified_at' => $user->email_verified_at,
            'is_active' => $user->is_active,
            'roles' => $roles,
            'locations' => $locations,
        ];
    }
}
