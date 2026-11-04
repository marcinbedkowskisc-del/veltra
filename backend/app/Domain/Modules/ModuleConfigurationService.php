<?php

namespace App\Domain\Modules;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\SecurityMutation;
use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\LocationType;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ModuleConfigurationService
{
    public function __construct(
        private ModuleCatalog $catalog,
        private BusinessAuthorization $authorization,
        private SecurityMutation $mutations,
        private AuthorizationMutationContext $contexts,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        return array_map(fn (ModuleDefinition $definition): array => $this->describe($definition), array_values($this->catalog->all()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function states(User $actor, Location $context, Location $location): array
    {
        $this->authorizeLocationRead($actor, $context, $location);
        $states = LocationModuleState::query()->where('location_id', $location->id)->get()->keyBy('module_key');

        return array_map(function (ModuleDefinition $definition) use ($location, $states): array {
            $configured = (bool) ($states->get($definition->key)?->enabled ?? false);

            return [
                ...$this->describe($definition),
                'configured_state' => $configured ? 'ENABLED' : 'DISABLED',
                'available' => app(ModuleAvailability::class)->enabled($location, $definition->key),
            ];
        }, array_values($this->catalog->all()));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{operation_id: string, requested_state: string, changed_count: int, unchanged_count: int, targets: list<array<string, mixed>>}
     */
    public function configure(
        User $actor,
        Location $context,
        #[SensitiveParameter] array $input,
    ): array {
        $operationId = Str::uuid()->toString();
        $enabled = (bool) $input['enabled'];
        $moduleKeys = $input['module_ids'];
        $locationIds = $input['location_ids'] ?? null;
        $allCompatible = (bool) ($input['all_compatible_locations'] ?? false);
        $password = $input['current_password'];
        $auditPairs = [];
        $failureCode = 'operation_rejected';

        try {
            return $this->contexts->run('module.configure', $actor->id, function () use (
                $actor,
                $context,
                $enabled,
                $moduleKeys,
                $locationIds,
                $allCompatible,
                $password,
                $operationId,
                &$auditPairs,
                &$failureCode,
            ): array {
                return DB::transaction(function () use (
                    $actor,
                    $context,
                    $enabled,
                    $moduleKeys,
                    $locationIds,
                    $allCompatible,
                    $password,
                    $operationId,
                    &$auditPairs,
                    &$failureCode,
                ): array {
                    $organization = Organization::whereKey($actor->organization_id)->lockForUpdate()->first();
                    if (! $organization) {
                        $failureCode = 'organization_unavailable';
                        throw new BusinessRuleViolation(__('messages.module_configuration_organization_unavailable'));
                    }

                    $freshActor = User::with('organization')->find($actor->id);
                    $freshContext = Location::find($context->id);
                    if (! $freshActor || ! $freshContext || ! $this->authorization->eligible($freshActor, $freshContext)
                        || ! $this->authorization->hasRole($freshActor, BusinessRole::Administrator)) {
                        $failureCode = 'administrator_authorization_failed';
                        throw new AuthorizationException;
                    }

                    $definitions = [];
                    $unknownModules = [];
                    foreach ($moduleKeys as $key) {
                        $definition = $this->catalog->find($key);
                        if (! $definition) {
                            $unknownModules[] = $key;

                            continue;
                        }
                        $definitions[$key] = $definition;
                    }

                    [$locations, $selectionResolved] = $this->resolveLocations(
                        $organization->id,
                        $locationIds,
                        $allCompatible,
                    );
                    $pairs = [];
                    $moduleHasTargets = [];
                    foreach ($moduleKeys as $key) {
                        $definition = $definitions[$key] ?? null;
                        $targets = $allCompatible && $definition
                            ? $locations->filter(fn (Location $location): bool => $definition->supports($location->type))
                            : ($allCompatible ? collect() : $locations);
                        $moduleHasTargets[$key] = $targets->isNotEmpty();
                        foreach ($targets as $location) {
                            $pairs[] = ['location' => $location, 'module' => $key];
                        }
                    }

                    $stateRows = LocationModuleState::query()
                        ->where('organization_id', $organization->id)
                        ->whereIn('location_id', $locations->modelKeys())
                        ->orderBy('location_id')->orderBy('module_key')->lockForUpdate()->get();
                    $stateByKey = $stateRows->keyBy(fn (LocationModuleState $state): string => $state->location_id.'|'.$state->module_key);
                    foreach ($pairs as $pair) {
                        $location = $pair['location'];
                        $state = $stateByKey->get($location->id.'|'.$pair['module']);
                        $auditPairs[] = [
                            'location' => $location,
                            'module' => $pair['module'],
                            'previous' => (bool) ($state?->enabled ?? false),
                        ];
                    }

                    $this->confirmPassword($freshActor, $password, $failureCode);
                    if ($unknownModules !== []) {
                        $failureCode = 'unknown_module';
                        throw ValidationException::withMessages(['module_ids' => array_map(
                            fn (string $key): string => __('messages.module_unknown', ['module' => $key]),
                            $unknownModules,
                        )]);
                    }
                    if (! $selectionResolved) {
                        $failureCode = 'target_location_not_found';
                        throw new BusinessRuleViolation(__('messages.module_targets_invalid'));
                    }
                    foreach ($moduleHasTargets as $key => $hasTargets) {
                        if (! $hasTargets) {
                            $failureCode = 'no_compatible_locations';
                            throw new BusinessRuleViolation(__('messages.module_no_compatible_locations', ['module' => $definitions[$key]->name]));
                        }
                    }

                    foreach ($pairs as $pair) {
                        /** @var Location $location */
                        $location = $pair['location'];
                        /** @var ModuleDefinition $definition */
                        $definition = $definitions[$pair['module']];
                        if (! $this->authorization->eligible($freshActor, $freshContext, $location)) {
                            $failureCode = 'target_location_unauthorized';
                            throw new AuthorizationException(__('messages.module_target_authorization_required'));
                        }
                        if (! $definition->supports($location->type)) {
                            $failureCode = 'incompatible_location';
                            throw new BusinessRuleViolation(__('messages.module_incompatible_location', ['module' => $definition->name]));
                        }
                        if ($enabled && ! $definition->ready) {
                            $failureCode = 'module_not_ready';
                            throw new BusinessRuleViolation(__('messages.module_not_ready', ['module' => $definition->name]));
                        }
                    }

                    if ($enabled) {
                        foreach ($auditPairs as $pair) {
                            $definition = $definitions[$pair['module']];
                            foreach ($definition->dependencies as $dependencyKey) {
                                $dependency = $this->catalog->find($dependencyKey);
                                if (! $dependency || ! $dependency->ready || ! $dependency->supports($pair['location']->type)
                                    || ! $this->configuredEnabled($pair['location'], $dependencyKey, $stateByKey)) {
                                    $failureCode = 'dependency_missing';
                                    $dependencyName = $dependency?->name ?? $dependencyKey;
                                    throw new BusinessRuleViolation(__('messages.module_dependency_missing', [
                                        'module' => $definition->name,
                                        'dependency' => $dependencyName,
                                    ]));
                                }
                            }
                        }
                    } else {
                        foreach ($auditPairs as $pair) {
                            foreach ($this->catalog->all() as $dependent) {
                                if ($this->dependsTransitivelyOn($dependent, $pair['module'])
                                    && $this->configuredEnabled($pair['location'], $dependent->key, $stateByKey)) {
                                    $failureCode = 'enabled_dependent';
                                    throw new BusinessRuleViolation(__('messages.cannot_disable_module', [
                                        'module' => $pair['module'],
                                        'dependent' => $dependent->name,
                                    ]));
                                }
                            }
                        }
                    }

                    $timestamp = DB::scalar('SELECT clock_timestamp()');
                    $results = [];
                    $changedCount = 0;
                    foreach ($auditPairs as $pair) {
                        /** @var Location $location */
                        $location = $pair['location'];
                        $key = $location->id.'|'.$pair['module'];
                        $previous = $pair['previous'];
                        $state = $stateByKey->get($key);
                        $changed = $previous !== $enabled;

                        if ($changed) {
                            if ($state) {
                                $state->update(['enabled' => $enabled, 'updated_by' => $freshActor->id, 'updated_at' => $timestamp]);
                            } elseif ($enabled) {
                                $state = LocationModuleState::create([
                                    'organization_id' => $organization->id,
                                    'location_id' => $location->id,
                                    'module_key' => $pair['module'],
                                    'enabled' => true,
                                    'created_by' => $freshActor->id,
                                    'updated_by' => $freshActor->id,
                                    'created_at' => $timestamp,
                                    'updated_at' => $timestamp,
                                ]);
                                $stateByKey->put($key, $state);
                            }
                            $changedCount++;
                        }

                        $this->mutations->recordModuleEvent(
                            $freshActor, $location, $pair['module'], $previous, $enabled, $enabled,
                            'accepted', $operationId, 'module_attempt', 'accepted',
                        );
                        if ($changed) {
                            $this->mutations->recordModuleEvent(
                                $freshActor, $location, $pair['module'], $previous, $enabled, $enabled,
                                'accepted', $operationId, 'module_transition', 'state_changed',
                            );
                        }
                        $results[] = [
                            'location_id' => $location->id,
                            'module' => $pair['module'],
                            'previous_state' => $previous ? 'ENABLED' : 'DISABLED',
                            'effective_state' => $enabled ? 'ENABLED' : 'DISABLED',
                            'changed' => $changed,
                        ];
                    }

                    return [
                        'operation_id' => $operationId,
                        'requested_state' => $enabled ? 'ENABLED' : 'DISABLED',
                        'changed_count' => $changedCount,
                        'unchanged_count' => count($results) - $changedCount,
                        'targets' => $results,
                    ];
                });
            });
        } catch (AuthorizationException|BusinessRuleViolation|QueryException|TooManyRequestsHttpException|ValidationException $exception) {
            $this->recordRejected($actor, $operationId, $moduleKeys, $enabled, $auditPairs, $failureCode);
            throw $exception;
        }
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int}}
     */
    public function history(User $actor, Location $context, Location $location, int $perPage): array
    {
        $this->authorizeLocationRead($actor, $context, $location);
        if (! $this->authorization->hasRole($actor, BusinessRole::Administrator)) {
            throw new AuthorizationException;
        }
        $events = DB::table('security_events')
            ->where('organization_id', $actor->organization_id)
            ->where('location_id', $location->id)
            ->whereIn('event_kind', ['module_attempt', 'module_transition'])
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(min($perPage, 100));

        return [
            'data' => $events->items(),
            'meta' => ['current_page' => $events->currentPage(), 'last_page' => $events->lastPage()],
        ];
    }

    private function authorizeLocationRead(User $actor, Location $context, Location $location): void
    {
        if (! $this->authorization->eligible($actor, $context, $location)
            || ! $this->authorization->allows($actor, $context, $location, Permission::LocationView)) {
            throw new AuthorizationException;
        }
    }

    private function confirmPassword(User $actor, #[SensitiveParameter] string $password, string &$failureCode): void
    {
        $key = 'module-administrator-confirm:'.$actor->id.'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $failureCode = 'password_confirmation_rate_limited';
            throw new TooManyRequestsHttpException(60, __('messages.fresh_password_confirmation_rate_limited'));
        }
        if (! Hash::check($password, $actor->password)) {
            RateLimiter::hit($key, 60);
            $failureCode = 'password_confirmation_failed';
            throw new AuthorizationException(__('messages.fresh_password_confirmation_required'));
        }
        RateLimiter::clear($key);
    }

    /**
     * @param  list<string>|null  $locationIds
     * @return array{0: \Illuminate\Database\Eloquent\Collection<int, Location>, 1: bool}
     */
    private function resolveLocations(
        string $organizationId,
        ?array $locationIds,
        bool $allCompatible,
    ): array {
        $query = Location::query()->where('organization_id', $organizationId)->where('is_active', true);
        if (! $allCompatible) {
            $query->whereIn('id', $locationIds);
        }
        $locations = $query->orderBy('id')->lockForUpdate()->get();

        return [$locations, $allCompatible || $locations->count() === count($locationIds ?? [])];
    }

    /**
     * @param  Collection<string, LocationModuleState>  $states
     */
    private function configuredEnabled(Location $location, string $module, Collection $states): bool
    {
        return (bool) ($states->get($location->id.'|'.$module)?->enabled ?? false);
    }

    private function dependsTransitivelyOn(ModuleDefinition $definition, string $key): bool
    {
        foreach ($definition->dependencies as $dependency) {
            if ($dependency === $key) {
                return true;
            }
            $parent = $this->catalog->find($dependency);
            if ($parent && $this->dependsTransitivelyOn($parent, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(ModuleDefinition $definition): array
    {
        return [
            'module' => $definition->key,
            'name' => $definition->name,
            'permission' => $definition->permission,
            'readiness' => $definition->ready ? 'READY' : 'NOT_READY',
            'location_types' => array_map(fn (LocationType $type): string => $type->value, $definition->locationTypes),
            'dependencies' => $definition->dependencies,
        ];
    }

    /**
     * @param  list<string>  $moduleKeys
     * @param  list<array{location: Location, module: string, previous: bool}>  $pairs
     */
    private function recordRejected(
        User $actor,
        string $operationId,
        array $moduleKeys,
        bool $requested,
        array $pairs,
        string $detailCode,
    ): void {
        DB::transaction(function () use ($actor, $operationId, $moduleKeys, $requested, $pairs, $detailCode): void {
            if ($pairs === []) {
                foreach ($moduleKeys as $key) {
                    $this->mutations->recordModuleEvent(
                        $actor, null, $key, null, $requested, null,
                        'rejected', $operationId, 'module_attempt', $detailCode,
                    );
                }

                return;
            }
            foreach ($pairs as $pair) {
                $this->mutations->recordModuleEvent(
                    $actor, $pair['location'], $pair['module'], $pair['previous'], $requested, $pair['previous'],
                    'rejected', $operationId, 'module_attempt', $detailCode,
                );
            }
        });
    }
}
