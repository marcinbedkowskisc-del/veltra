<?php

namespace App\Domain\Modules;

use App\Domain\Authorization\AuthorizationMutationCommitException;
use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

final class OrganizationCapabilityService
{
    public function __construct(
        private readonly OrganizationCapabilityCatalog $catalog,
        private readonly BusinessAuthorization $authorization,
        private readonly AuthorizationMutationContext $contexts,
    ) {}

    /**
     * @return array{capability: string, scope: string, readiness: string, configured_state: string, available: bool, state_version: int, operations: list<string>}
     */
    public function status(User $actor, Location $context, string $key): array
    {
        $definition = $this->definition($key);
        $freshActor = User::with('organization')->find($actor->id);
        $freshContext = Location::find($context->id);
        if (! $freshActor || ! $freshContext || ! $this->isAdministrator($freshActor, $freshContext, $definition)) {
            throw new AuthorizationException;
        }

        $state = DB::table('organization_capability_states')
            ->where('organization_id', $freshActor->organization_id)
            ->where('capability_key', $definition->key)
            ->first(['enabled', 'state_version']);
        $enabled = $state !== null && $this->databaseBoolean($state->enabled);

        return [
            'capability' => $definition->key,
            'scope' => $definition->scope,
            'readiness' => $definition->ready ? 'READY' : 'NOT_READY',
            'configured_state' => $enabled ? 'ENABLED' : 'DISABLED',
            'available' => $definition->ready && $enabled,
            'state_version' => (int) ($state?->state_version ?? 0),
            'operations' => $definition->operations,
        ];
    }

    /**
     * @param  array{enabled: bool, expected_state_version: int, current_password: string}  $input
     * @return array{operation_id: string, capability: string, previous_state: string, requested_state: string, effective_state: string, available: bool, changed: bool, state_version: int}
     */
    public function configure(
        User $actor,
        Location $context,
        string $key,
        string $operationId,
        #[SensitiveParameter] array $input,
    ): array {
        $definition = $this->definition($key);
        $requested = (bool) $input['enabled'];
        $expectedVersion = (int) $input['expected_state_version'];
        $password = $input['current_password'];
        $failureCode = 'operation_rejected';

        try {
            return $this->contexts->runWithCommitOutcome(
                AuthorizationMutationScope::organizationCapabilityConfigure(),
                $actor->id,
                function () use (
                    $actor,
                    $context,
                    $definition,
                    $operationId,
                    $requested,
                    $expectedVersion,
                    $password,
                    &$failureCode,
                ): array {
                    return DB::transaction(function () use (
                        $actor,
                        $context,
                        $definition,
                        $operationId,
                        $requested,
                        $expectedVersion,
                        $password,
                        &$failureCode,
                    ): array {
                        $organization = Organization::whereKey($actor->organization_id)->lockForUpdate()->first();
                        if (! $organization) {
                            $failureCode = 'organization_unavailable';
                            throw new AuthorizationException;
                        }

                        $freshActor = User::with('organization')->find($actor->id);
                        $freshContext = Location::find($context->id);
                        if (! $freshActor || ! $freshContext || ! $this->isAdministrator($freshActor, $freshContext, $definition)) {
                            $failureCode = 'administrator_authorization_failed';
                            throw new AuthorizationException;
                        }

                        $state = DB::table('organization_capability_states')
                            ->where('organization_id', $organization->id)
                            ->where('capability_key', $definition->key)
                            ->lockForUpdate()
                            ->first();
                        $previousEnabled = $state !== null && $this->databaseBoolean($state->enabled);
                        $previousVersion = (int) ($state?->state_version ?? 0);

                        $this->confirmPassword($freshActor, $password, $failureCode);
                        if ($expectedVersion !== $previousVersion) {
                            $failureCode = 'state_version_conflict';
                            throw new OrganizationCapabilityException(
                                'CAPABILITY_STATE_VERSION_CONFLICT',
                                __('messages.organization_capability_state_version_conflict'),
                                409,
                            );
                        }
                        if ($requested && ! $definition->ready) {
                            $failureCode = 'capability_not_ready';
                            throw new OrganizationCapabilityException(
                                'CAPABILITY_NOT_READY',
                                __('messages.organization_capability_not_ready'),
                                409,
                            );
                        }

                        $changed = $previousEnabled !== $requested;
                        $resultVersion = $previousVersion + ($changed ? 1 : 0);
                        $effective = $changed ? $requested : $previousEnabled;
                        $detailCode = $changed ? 'activation_accepted' : 'activation_idempotent';
                        $transitionId = $changed ? Str::uuid()->toString() : null;

                        $this->recordAccepted(
                            $freshActor,
                            $freshContext,
                            $definition,
                            $operationId,
                            $requested,
                            $previousEnabled,
                            $effective,
                            $expectedVersion,
                            $previousVersion,
                            $resultVersion,
                            $changed,
                            $detailCode,
                            $transitionId,
                        );

                        if ($changed) {
                            $timestamp = DB::scalar('SELECT clock_timestamp()');
                            $values = [
                                'enabled' => $requested,
                                'updated_by' => $freshActor->id,
                                'updated_at' => $timestamp,
                                'state_version' => $resultVersion,
                                'last_transition_event_id' => $transitionId,
                            ];
                            if ($state) {
                                DB::table('organization_capability_states')
                                    ->where('id', $state->id)
                                    ->update($values);
                            } else {
                                DB::table('organization_capability_states')->insert([
                                    'id' => Str::uuid()->toString(),
                                    'organization_id' => $organization->id,
                                    'capability_key' => $definition->key,
                                    ...$values,
                                    'created_by' => $freshActor->id,
                                    'created_at' => $timestamp,
                                ]);
                            }
                        }

                        return [
                            'operation_id' => $operationId,
                            'capability' => $definition->key,
                            'previous_state' => $previousEnabled ? 'ENABLED' : 'DISABLED',
                            'requested_state' => $requested ? 'ENABLED' : 'DISABLED',
                            'effective_state' => $effective ? 'ENABLED' : 'DISABLED',
                            'available' => $definition->ready && $effective,
                            'changed' => $changed,
                            'state_version' => $resultVersion,
                        ];
                    });
                },
            );
        } catch (AuthorizationMutationCommitException $exception) {
            if (! $exception->transactionRolledBack) {
                Log::error('Organization capability commit outcome is uncertain.', [
                    'operation_id' => $operationId,
                    'sqlstate' => $exception->getPrevious()?->getCode(),
                ]);

                throw new OrganizationCapabilityException(
                    'CAPABILITY_COMMIT_OUTCOME_UNKNOWN',
                    __('messages.organization_capability_commit_outcome_unknown'),
                    503,
                    $exception,
                );
            }

            $this->recordRejected(
                $actor,
                $context,
                $definition,
                $operationId,
                $requested,
                $expectedVersion,
                $failureCode,
                $exception,
            );
            Log::error('Organization capability commit failed and its transaction was rolled back.', [
                'operation_id' => $operationId,
                'sqlstate' => $exception->getPrevious()?->getCode(),
            ]);

            throw new OrganizationCapabilityException(
                'CAPABILITY_AUDIT_PERSISTENCE_FAILED',
                __('messages.organization_capability_audit_failed'),
                500,
                $exception,
            );
        } catch (AuthorizationException|OrganizationCapabilityException|QueryException|TooManyRequestsHttpException $exception) {
            $this->recordRejected(
                $actor,
                $context,
                $definition,
                $operationId,
                $requested,
                $expectedVersion,
                $failureCode,
                $exception,
            );
            throw $exception;
        }
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function history(User $actor, Location $context, string $key, int $perPage, int $page): array
    {
        $definition = $this->definition($key);
        $freshActor = User::with('organization')->find($actor->id);
        $freshContext = Location::find($context->id);
        if (! $freshActor || ! $freshContext || ! $this->isAdministrator($freshActor, $freshContext, $definition)) {
            throw new AuthorizationException;
        }

        $events = DB::table('organization_capability_events')
            ->where('organization_id', $freshActor->organization_id)
            ->where('capability_key', $definition->key)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(min($perPage, 100), [
                'id', 'organization_id', 'capability_key', 'operation_id', 'event_kind',
                'outcome', 'actor_id', 'operating_location_id', 'previous_enabled',
                'requested_enabled', 'effective_enabled', 'expected_state_version',
                'previous_state_version', 'result_state_version', 'state_changed',
                'detail_code', 'occurred_at',
            ], 'page', $page);

        return [
            'data' => $events->items(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ];
    }

    private function definition(string $key): OrganizationCapabilityDefinition
    {
        $definition = $this->catalog->find($key);
        if (! $definition) {
            throw new OrganizationCapabilityException(
                'CAPABILITY_NOT_FOUND',
                __('messages.resource_not_found'),
                404,
            );
        }

        return $definition;
    }

    private function isAdministrator(
        User $actor,
        Location $context,
        OrganizationCapabilityDefinition $definition,
    ): bool {
        return $this->authorization->eligible($actor, $context)
            && in_array($context->type, $definition->operatingContextTypes, true)
            && $this->authorization->hasRole($actor, BusinessRole::Administrator);
    }

    private function confirmPassword(User $actor, #[SensitiveParameter] string $password, string &$failureCode): void
    {
        $key = 'organization-capability-administrator-confirm:'.$actor->id.'|'.request()->ip();
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

    private function recordAccepted(
        User $actor,
        Location $context,
        OrganizationCapabilityDefinition $definition,
        string $operationId,
        bool $requested,
        bool $previous,
        bool $effective,
        int $expectedVersion,
        int $previousVersion,
        int $resultVersion,
        bool $changed,
        string $detailCode,
        ?string $transitionId,
    ): void {
        $this->contexts->run(AuthorizationMutationScope::organizationCapabilityAudit(), $actor->id, function () use (
            $actor,
            $context,
            $definition,
            $operationId,
            $requested,
            $previous,
            $effective,
            $expectedVersion,
            $previousVersion,
            $resultVersion,
            $changed,
            $detailCode,
            $transitionId,
        ): void {
            DB::table('organization_capability_events')->insert(
                $this->eventValues(
                    $actor, $context, $definition, $operationId, 'activation_attempt', 'accepted',
                    $requested, $previous, $effective, $expectedVersion, $previousVersion,
                    $resultVersion, $changed, $detailCode,
                ),
            );
            if ($transitionId !== null) {
                DB::table('organization_capability_events')->insert(
                    $this->eventValues(
                        $actor, $context, $definition, $operationId, 'activation_transition', 'accepted',
                        $requested, $previous, $effective, $expectedVersion, $previousVersion,
                        $resultVersion, true, $detailCode, $transitionId,
                    ),
                );
            }
        });
    }

    private function recordRejected(
        User $actor,
        Location $context,
        OrganizationCapabilityDefinition $definition,
        string $operationId,
        bool $requested,
        int $expectedVersion,
        string $detailCode,
        Throwable $operationFailure,
    ): void {
        try {
            $this->contexts->run(AuthorizationMutationScope::organizationCapabilityAudit(), $actor->id, function () use (
                $actor,
                $context,
                $definition,
                $operationId,
                $requested,
                $expectedVersion,
                $detailCode,
            ): void {
                DB::transaction(function () use (
                    $actor,
                    $context,
                    $definition,
                    $operationId,
                    $requested,
                    $expectedVersion,
                    $detailCode,
                ): void {
                    $organization = Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
                    $state = DB::table('organization_capability_states')
                        ->where('organization_id', $organization->id)
                        ->where('capability_key', $definition->key)
                        ->lockForUpdate()
                        ->first();
                    $previous = $state !== null && $this->databaseBoolean($state->enabled);
                    $version = (int) ($state?->state_version ?? 0);

                    DB::table('organization_capability_events')->insert(
                        $this->eventValues(
                            $actor, $context, $definition, $operationId, 'activation_attempt', 'rejected',
                            $requested, $previous, $previous, $expectedVersion, $version,
                            $version, false, $detailCode,
                        ),
                    );
                });
            });
        } catch (Throwable $auditFailure) {
            throw new OrganizationCapabilityAuditException($auditFailure, $operationFailure);
        }
    }

    private function databaseBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventValues(
        User $actor,
        Location $context,
        OrganizationCapabilityDefinition $definition,
        string $operationId,
        string $kind,
        string $outcome,
        bool $requested,
        bool $previous,
        bool $effective,
        int $expectedVersion,
        int $previousVersion,
        int $resultVersion,
        bool $changed,
        string $detailCode,
        ?string $id = null,
    ): array {
        return [
            'id' => $id ?? Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'capability_key' => $definition->key,
            'operation_id' => $operationId,
            'event_kind' => $kind,
            'outcome' => $outcome,
            'actor_id' => $actor->id,
            'operating_location_id' => $context->id,
            'previous_enabled' => $previous,
            'requested_enabled' => $requested,
            'effective_enabled' => $effective,
            'expected_state_version' => $expectedVersion,
            'previous_state_version' => $previousVersion,
            'result_state_version' => $resultVersion,
            'state_changed' => $changed,
            'detail_code' => $detailCode,
            'occurred_at' => DB::raw('clock_timestamp()'),
        ];
    }
}
