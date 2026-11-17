<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Allergens\InternationalAllergenDictionaryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdministrationAllergenCanonicalUpdateRequest;
use App\Http\Requests\AdministrationAllergenEntryCreateRequest;
use App\Http\Requests\AdministrationAllergenEntryUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdministrationAllergenController extends Controller
{
    public function dictionaries(Request $request, InternationalAllergenDictionaryService $service): JsonResponse
    {
        return response()->json(['data' => $service->dictionaries(
            $request->user(),
            $request->attributes->get('operating_location'),
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function entries(
        Request $request,
        string $version,
        InternationalAllergenDictionaryService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:active,archived,all'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $service->dictionaryEntries(
            $request->user(),
            $request->attributes->get('operating_location'),
            $version,
            $validated['status'] ?? 'all',
            $validated['search'] ?? null,
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function canonicalAllergens(Request $request, InternationalAllergenDictionaryService $service): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $service->canonicalAllergens(
            $request->user(),
            $request->attributes->get('operating_location'),
            $validated['search'] ?? null,
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function history(
        Request $request,
        string $version,
        InternationalAllergenDictionaryService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->history(
            $request->user(),
            $request->attributes->get('operating_location'),
            $version,
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function storeEntry(
        AdministrationAllergenEntryCreateRequest $request,
        string $version,
        InternationalAllergenDictionaryService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->createEntry(
            $request->user(),
            $request->attributes->get('operating_location'),
            $version,
            $request->validated(),
        )], 201, ['Cache-Control' => 'private, no-store']);
    }

    public function updateEntry(
        AdministrationAllergenEntryUpdateRequest $request,
        string $entry,
        InternationalAllergenDictionaryService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->updateEntry(
            $request->user(),
            $request->attributes->get('operating_location'),
            $entry,
            $request->validated(),
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function archiveEntry(
        Request $request,
        string $entry,
        InternationalAllergenDictionaryService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->archiveEntry(
            $request->user(),
            $request->attributes->get('operating_location'),
            $entry,
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function updateCanonical(
        AdministrationAllergenCanonicalUpdateRequest $request,
        string $version,
        string $allergen,
        InternationalAllergenDictionaryService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->updateCanonical(
            $request->user(),
            $request->attributes->get('operating_location'),
            $version,
            $allergen,
            $request->validated(),
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function archiveCanonical(
        Request $request,
        string $version,
        string $allergen,
        InternationalAllergenDictionaryService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->archiveCanonical(
            $request->user(),
            $request->attributes->get('operating_location'),
            $version,
            $allergen,
        )], 200, ['Cache-Control' => 'private, no-store']);
    }
}
