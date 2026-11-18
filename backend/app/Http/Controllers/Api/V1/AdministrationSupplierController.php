<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Suppliers\SupplierDirectoryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdministrationSupplierContactStoreRequest;
use App\Http\Requests\AdministrationSupplierContactUpdateRequest;
use App\Http\Requests\AdministrationSupplierListRequest;
use App\Http\Requests\AdministrationSupplierMutationRequest;
use App\Http\Requests\AdministrationSupplierSimilarNamesRequest;
use App\Http\Requests\AdministrationSupplierStoreRequest;
use App\Http\Requests\AdministrationSupplierUpdateRequest;
use Illuminate\Http\JsonResponse;

final class AdministrationSupplierController extends Controller
{
    public function index(AdministrationSupplierListRequest $request, SupplierDirectoryService $service): JsonResponse
    {
        return response()->json($service->list(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        ), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function show(
        AdministrationSupplierMutationRequest $request,
        string $supplier,
        SupplierDirectoryService $service,
    ): JsonResponse {
        return response()->json($service->show(
            $request->user(),
            $request->attributes->get('operating_location'),
            $supplier,
        ), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function similarNames(
        AdministrationSupplierSimilarNamesRequest $request,
        SupplierDirectoryService $service,
    ): JsonResponse {
        $input = $request->validated();

        return response()->json($service->similarNames(
            $request->user(),
            $request->attributes->get('operating_location'),
            $input['official_name'],
            $input['exclude_supplier_id'] ?? null,
        ), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function store(AdministrationSupplierStoreRequest $request, SupplierDirectoryService $service): JsonResponse
    {
        return response()->json($service->create(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
            $request->idempotencyKey(),
        ), 201, ['Cache-Control' => 'private, no-store']);
    }

    public function update(
        AdministrationSupplierUpdateRequest $request,
        string $supplier,
        SupplierDirectoryService $service,
    ): JsonResponse {
        return response()->json($service->update(
            $request->user(),
            $request->attributes->get('operating_location'),
            $supplier,
            $request->validated(),
            $request->idempotencyKey(),
        ), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function archive(
        AdministrationSupplierMutationRequest $request,
        string $supplier,
        SupplierDirectoryService $service,
    ): JsonResponse {
        return response()->json($service->archive(
            $request->user(),
            $request->attributes->get('operating_location'),
            $supplier,
            $request->idempotencyKey(),
        ), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function restore(
        AdministrationSupplierMutationRequest $request,
        string $supplier,
        SupplierDirectoryService $service,
    ): JsonResponse {
        return response()->json($service->restore(
            $request->user(),
            $request->attributes->get('operating_location'),
            $supplier,
            $request->idempotencyKey(),
        ), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function storeContact(
        AdministrationSupplierContactStoreRequest $request,
        string $supplier,
        SupplierDirectoryService $service,
    ): JsonResponse {
        return response()->json($service->createContact(
            $request->user(),
            $request->attributes->get('operating_location'),
            $supplier,
            $request->validated(),
            $request->idempotencyKey(),
        ), 201, ['Cache-Control' => 'private, no-store']);
    }

    public function updateContact(
        AdministrationSupplierContactUpdateRequest $request,
        string $supplier,
        string $contact,
        SupplierDirectoryService $service,
    ): JsonResponse {
        return response()->json($service->updateContact(
            $request->user(),
            $request->attributes->get('operating_location'),
            $supplier,
            $contact,
            $request->validated(),
            $request->idempotencyKey(),
        ), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function removeContact(
        AdministrationSupplierMutationRequest $request,
        string $supplier,
        string $contact,
        SupplierDirectoryService $service,
    ): JsonResponse {
        return response()->json($service->removeContact(
            $request->user(),
            $request->attributes->get('operating_location'),
            $supplier,
            $contact,
            $request->idempotencyKey(),
        ), 200, ['Cache-Control' => 'private, no-store']);
    }
}
