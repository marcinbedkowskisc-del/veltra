<?php

namespace App\Domain\Authorization;

use App\Domain\Installation\BusinessRuleViolation;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SecurityMutation
{
    public function __construct(private AuthorizationMutationContext $contexts) {}

    public function record(?User $actor, User $target, ?Location $location, string $action, string $outcome, ?string $subject = null): void
    {
        $this->contexts->run('security.event', $actor?->id, function () use ($actor, $target, $location, $action, $outcome, $subject): void {
            DB::table('security_events')->insert([
                'id' => Str::uuid()->toString(), 'organization_id' => $target->organization_id,
                'actor_id' => $actor?->id, 'target_id' => $target->id, 'location_id' => $location?->id,
                'action' => $action, 'outcome' => $outcome, 'subject_id' => $subject,
            ]);
        });
    }

    public function recordModuleEvent(
        User $actor,
        ?Location $location,
        string $module,
        ?bool $previous,
        bool $requested,
        ?bool $effective,
        string $outcome,
        string $operationId,
        string $eventKind,
        string $detailCode,
    ): void {
        $this->contexts->run('module.audit', $actor->id, function () use (
            $actor, $location, $outcome, $operationId, $eventKind, $module, $previous, $requested, $effective, $detailCode,
        ): void {
            DB::table('security_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $actor->organization_id,
                'actor_id' => $actor->id,
                'target_id' => null,
                'location_id' => $location?->id,
                'action' => 'module.configure',
                'outcome' => $outcome,
                'operation_id' => $operationId,
                'event_kind' => $eventKind,
                'module_key' => $module,
                'previous_enabled' => $previous,
                'requested_enabled' => $requested,
                'effective_enabled' => $effective,
                'detail_code' => $detailCode,
            ]);
        });
    }

    public function run(User $actor, User $target, ?Location $location, string $action, Closure $operation): mixed
    {
        try {
            return $this->contexts->run($action, $actor->id, function () use ($actor, $target, $location, $action, $operation): mixed {
                Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
                $result = $operation();
                $this->record($actor, $target, $location, $action, 'accepted', $result instanceof Model ? $result->getKey() : null);

                return $result;
            });
        } catch (AuthorizationException|BusinessRuleViolation $exception) {
            // Record outside the rolled-back mutation so rejected decisions are not lost.
            $this->record($actor, $target, $location, $action, 'rejected');
            throw $exception;
        }
    }
}
