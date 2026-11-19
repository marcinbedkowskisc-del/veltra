<?php

namespace App\Domain\Authorization;

use App\Domain\Installation\LocationType;
use App\Models\Location;
use App\Models\ReportShare;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ReportAuthorization
{
    public function __construct(private BusinessAuthorization $authorization, private SecurityMutation $mutations, private ReportCatalog $catalog) {}

    public function share(User $actor, Location $context, User $boss, string $reportId): ReportShare
    {
        $kitchen = $this->catalog->sourceKitchen($reportId);

        return $this->mutations->run($actor, $boss, $kitchen, 'report.share', function () use ($actor, $context, $boss, $reportId, $kitchen): ReportShare {
            if (! $kitchen || $kitchen->type !== LocationType::CentralKitchen || ! $this->authorization->eligible($actor, $context, $kitchen)
                || $boss->organization_id !== $actor->organization_id || ! $boss->fresh()->is_active
                || ! $this->authorization->hasRole($boss, BusinessRole::Boss)
                || ! ($this->authorization->hasRole($actor, BusinessRole::Administrator) || $this->authorization->manages($actor, $context, $kitchen))) {
                throw new AuthorizationException;
            }

            return ReportShare::create(['organization_id' => $actor->organization_id, 'user_id' => $boss->id,
                'location_id' => $kitchen->id, 'report_id' => $reportId, 'granted_by' => $actor->id]);
        });
    }

    public function revoke(User $actor, Location $context, ReportShare $share): void
    {
        $target = User::findOrFail($share->user_id);
        $kitchen = Location::findOrFail($share->location_id);
        $this->mutations->run($actor, $target, $kitchen, 'report.share_revoke', function () use ($actor, $context, $share, $kitchen): void {
            if (! $this->authorization->eligible($actor, $context, $kitchen)
                || ! ($this->authorization->hasRole($actor, BusinessRole::Administrator) || $this->authorization->manages($actor, $context, $kitchen))) {
                throw new AuthorizationException;
            }
            ReportShare::where('report_id', $share->report_id)->where('user_id', $share->user_id)
                ->whereNull('revoked_at')->update(['revoked_at' => DB::scalar('SELECT clock_timestamp()'), 'revoked_by' => $actor->id]);
        });
    }

    public function canViewShared(User $boss, Location $context, string $reportId): bool
    {
        $kitchen = $this->catalog->sourceKitchen($reportId);

        return $kitchen && $this->authorization->eligible($boss, $context, $kitchen)
            && $this->authorization->hasRole($boss, BusinessRole::Boss)
            && app(BusinessVisibility::class)->decide($boss, $context, new BusinessVisibilitySurface(
                'boss.report.shared',
                BusinessVisibilitySource::Boss,
                'detail',
                Permission::ReportsView,
                [BossVisibilityCategory::ReportsAnalytics],
                BusinessDataProvenance::location($kitchen->id),
                $kitchen,
                Permission::ReportsView->module(),
            ))->allowed
            && ReportShare::where('user_id', $boss->id)->where('report_id', $reportId)->where('location_id', $kitchen->id)->whereNull('revoked_at')->exists();
    }

    public function authorizeExport(User $actor, Location $context, Location $target, Permission $source): void
    {
        $this->mutations->run($actor, $actor, $target, 'report.export_authorize.'.$source->value, function () use ($actor, $context, $target, $source): void {
            if (! $this->authorization->canExport($actor, $context, $target, $source)) {
                throw new AuthorizationException;
            }
        });
    }
}
