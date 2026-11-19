<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorization\BossLocationRestrictions;
use App\Domain\Authorization\BossVisibilityCategory;
use App\Domain\Authorization\BusinessDataProvenance;
use App\Domain\Authorization\BusinessVisibility;
use App\Domain\Authorization\BusinessVisibilitySource;
use App\Domain\Authorization\BusinessVisibilitySurface;
use App\Domain\Authorization\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BossLocationController extends Controller
{
    public function index(Request $request, BossLocationRestrictions $restrictions, BusinessVisibility $visibility): JsonResponse
    {
        $input = $request->validate(['type' => 'sometimes|in:CENTRAL_KITCHEN,RESTAURANT']);
        $decision = $visibility->decide($request->user(), $request->attributes->get('operating_location'), new BusinessVisibilitySurface(
            'boss.location_directory',
            BusinessVisibilitySource::Boss,
            'list',
            Permission::LocationView,
            [],
            BusinessDataProvenance::organization(),
        ));
        if (! $decision->allowed) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }
        $locations = $restrictions->visibleLocations($request->user(), $request->attributes->get('operating_location'))
            ->when(isset($input['type']), fn ($query) => $query->where('type', $input['type']))
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'type']);

        return $this->response($locations);
    }

    public function showRestrictions(Request $request, string $user, BossLocationRestrictions $restrictions): JsonResponse
    {
        return $this->response($restrictions->show($request->user(), $request->attributes->get('operating_location'), $user));
    }

    public function updateRestrictions(Request $request, string $user, BossLocationRestrictions $restrictions): JsonResponse
    {
        $input = $request->validate([
            'version' => 'required|integer|min:0|max:2147483646',
            'excluded_location_ids' => 'present|array|list',
            'excluded_location_ids.*' => 'required|uuid|distinct:ignore_case',
            'policy_schema_version' => 'sometimes|integer|in:2',
            'excluded_categories' => 'present_with:policy_schema_version|array|list',
            'excluded_categories.*' => ['required', 'string', 'distinct', 'in:'.implode(',', array_map(
                fn (BossVisibilityCategory $category): string => $category->value,
                BossVisibilityCategory::cases(),
            ))],
            'excluded_category_locations' => 'present_with:policy_schema_version|array|list',
            'excluded_category_locations.*' => 'required|array:category,location_id',
            'excluded_category_locations.*.category' => ['required', 'string', 'in:'.implode(',', array_map(
                fn (BossVisibilityCategory $category): string => $category->value,
                BossVisibilityCategory::cases(),
            ))],
            'excluded_category_locations.*.location_id' => 'required|uuid',
        ]);
        if (isset($input['policy_schema_version'])) {
            $pairs = array_map(fn (array $pair): string => $pair['category'].':'.strtolower($pair['location_id']), $input['excluded_category_locations']);
            if (count($pairs) !== count(array_unique($pairs))) {
                throw ValidationException::withMessages([
                    'excluded_category_locations' => [__('validation.distinct', ['attribute' => 'excluded_category_locations'])],
                ]);
            }
        } elseif (array_key_exists('excluded_categories', $request->all()) || array_key_exists('excluded_category_locations', $request->all())) {
            throw ValidationException::withMessages([
                'policy_schema_version' => [__('validation.required', ['attribute' => 'policy_schema_version'])],
            ]);
        }

        return $this->response($restrictions->replace(
            $request->user(), $request->attributes->get('operating_location'), $user,
            (int) $input['version'], $input['excluded_location_ids'],
            isset($input['policy_schema_version']) ? $input['excluded_categories'] : null,
            isset($input['policy_schema_version']) ? $input['excluded_category_locations'] : null,
        ));
    }

    private function response(mixed $data): JsonResponse
    {
        return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }
}
