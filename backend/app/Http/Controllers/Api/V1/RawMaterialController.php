<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialCommercialService;
use App\Domain\RawMaterials\RawMaterialCompatibilityAssessmentService;
use App\Domain\RawMaterials\RawMaterialHistoryService;
use App\Domain\RawMaterials\RawMaterialLocationShareAdministration;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Domain\RawMaterials\RawMaterialSkuReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListRawMaterialsRequest;
use App\Http\Requests\RawMaterialAllergenDeclarationRequest;
use App\Http\Requests\RawMaterialApprovalRequest;
use App\Http\Requests\RawMaterialBulkArchiveRequest;
use App\Http\Requests\RawMaterialBulkShareLocationsRequest;
use App\Http\Requests\RawMaterialBulkShareMaterialsRequest;
use App\Http\Requests\RawMaterialCategoryRenameRequest;
use App\Http\Requests\RawMaterialCategoryRequest;
use App\Http\Requests\RawMaterialCompatibilityAssessmentRequest;
use App\Http\Requests\RawMaterialDataRequest;
use App\Http\Requests\RawMaterialExchangeRateOverrideRequest;
use App\Http\Requests\RawMaterialExchangeRateQueryRequest;
use App\Http\Requests\RawMaterialExchangeRateRequest;
use App\Http\Requests\RawMaterialGrantRequest;
use App\Http\Requests\RawMaterialHistoryRequest;
use App\Http\Requests\RawMaterialOfferPriceRequest;
use App\Http\Requests\RawMaterialOfferRequest;
use App\Http\Requests\RawMaterialProductListRequest;
use App\Http\Requests\RawMaterialProductRequest;
use App\Http\Requests\RawMaterialProductRevisionRequest;
use App\Http\Requests\RawMaterialReasonRequest;
use App\Http\Requests\RawMaterialReferencePriceQueryRequest;
use App\Http\Requests\RawMaterialReferencePriceRequest;
use App\Http\Requests\RawMaterialRestatementRequest;
use App\Http\Requests\RawMaterialShareRequest;
use App\Http\Requests\RawMaterialShareRevocationRequest;
use App\Http\Requests\RawMaterialSkuReservationRequest;
use App\Http\Requests\RawMaterialStorageVariantsRequest;
use App\Http\Requests\RawMaterialSupplierCorrectionRequest;
use App\Http\Requests\RawMaterialSupplierListRequest;
use App\Http\Requests\RawMaterialSupplierRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RawMaterialController extends Controller
{
    public function workbench(ListRawMaterialsRequest $request, RawMaterialService $service): JsonResponse
    {
        return response()->json(
            $service->workbench($request->user(), $request->attributes->get('operating_location'), $request->validated()),
            200,
            ['Cache-Control' => 'private, no-store'],
        );
    }

    public function validateBulkArchive(Request $request, RawMaterialService $service): JsonResponse
    {
        $input = $request->validate(['items' => ['required', 'array']]);

        return response()->json(['data' => $service->validateBulkArchive(
            $request->user(), $request->attributes->get('operating_location'), $input['items'],
        )]);
    }

    public function bulkArchive(RawMaterialBulkArchiveRequest $request, RawMaterialService $service): JsonResponse
    {
        $input = $request->validated();

        return response()->json(['data' => $service->archiveBulk(
            $request->user(),
            $request->attributes->get('operating_location'),
            $input['items'],
            $input['reason'],
        )]);
    }

    public function deleteDraft(
        Request $request,
        string $material,
        RawMaterialService $service,
    ): Response {
        $service->deleteDraft(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->header('If-Match'),
        );

        return response()->noContent();
    }

    public function editor(Request $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->editor($request->user(), $request->attributes->get('operating_location'), $material)]);
    }

    public function completion(Request $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->completion($request->user(), $request->attributes->get('operating_location'), $material)]);
    }

    public function validateEdits(Request $request, string $material, RawMaterialService $service, string $section = 'basic'): JsonResponse
    {
        return response()->json(['data' => $service->validateEdits($request->user(), $request->attributes->get('operating_location'), $material, $request->all(), $section)]);
    }

    public function history(RawMaterialHistoryRequest $request, string $material, RawMaterialHistoryService $service): JsonResponse
    {
        return response()->json($service->history($request->user(), $request->attributes->get('operating_location'), $material, $request->validated()));
    }

    public function reserveSku(RawMaterialSkuReservationRequest $request, RawMaterialSkuReservationService $service): JsonResponse
    {
        return response()->json(['data' => $service->reserve(
            $request->user(), $request->attributes->get('operating_location'), $request->idempotencyKey(),
        )], 201);
    }

    public function suppliers(RawMaterialSupplierListRequest $request, RawMaterialCommercialService $service): JsonResponse
    {
        return response()->json($service->suppliers(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        ));
    }

    public function storeSupplier(RawMaterialSupplierRequest $request, RawMaterialCommercialService $service): JsonResponse
    {
        return response()->json($service->createSupplier(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
            $request->idempotencyKey(),
        ), 201);
    }

    public function archiveSupplier(
        RawMaterialReasonRequest $request,
        string $supplier,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->archiveSupplier(
            $request->user(),
            $request->attributes->get('operating_location'),
            $supplier,
            $request->validated('reason'),
            $request->idempotencyKey(),
        ));
    }

    public function correctSupplier(
        RawMaterialSupplierCorrectionRequest $request,
        string $supplier,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->correctSupplier(
            $request->user(),
            $request->attributes->get('operating_location'),
            $supplier,
            $request->validated(),
            $request->idempotencyKey(),
        ));
    }

    public function supplierProducts(
        RawMaterialProductListRequest $request,
        string $material,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->products(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated(),
        ));
    }

    public function storeSupplierProduct(
        RawMaterialProductRequest $request,
        string $material,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->createProduct(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated(),
            $request->idempotencyKey(),
        ), 201);
    }

    public function supplierProductRevisions(
        Request $request,
        string $product,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->productRevisions(
            $request->user(),
            $request->attributes->get('operating_location'),
            $product,
        ));
    }

    public function reviseSupplierProduct(
        RawMaterialProductRevisionRequest $request,
        string $product,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->reviseProduct(
            $request->user(),
            $request->attributes->get('operating_location'),
            $product,
            $request->validated(),
            $request->idempotencyKey(),
        ));
    }

    public function supplierCompatibilityAssessments(
        Request $request,
        string $product,
        RawMaterialCompatibilityAssessmentService $service,
    ): JsonResponse {
        return response()->json($service->history(
            $request->user(),
            $request->attributes->get('operating_location'),
            $product,
        ));
    }

    public function assessSupplierCompatibility(
        RawMaterialCompatibilityAssessmentRequest $request,
        string $product,
        RawMaterialCompatibilityAssessmentService $service,
    ): JsonResponse {
        return response()->json($service->assess(
            $request->user(),
            $request->attributes->get('operating_location'),
            $product,
            $request->validated(),
            $request->idempotencyKey(),
        ), 201);
    }

    public function archiveSupplierProduct(
        RawMaterialReasonRequest $request,
        string $product,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->archiveProduct(
            $request->user(),
            $request->attributes->get('operating_location'),
            $product,
            $request->validated('reason'),
            $request->idempotencyKey(),
        ));
    }

    public function supplierOffers(
        Request $request,
        string $product,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->offers(
            $request->user(),
            $request->attributes->get('operating_location'),
            $product,
        ));
    }

    public function storeSupplierOffer(
        RawMaterialOfferRequest $request,
        string $product,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->createOffer(
            $request->user(),
            $request->attributes->get('operating_location'),
            $product,
            $request->validated(),
            $request->idempotencyKey(),
        ), 201);
    }

    public function supplierOfferPriceEvents(
        Request $request,
        string $offer,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->offerPriceEvents(
            $request->user(),
            $request->attributes->get('operating_location'),
            $offer,
        ));
    }

    public function storeSupplierOfferPriceEvent(
        RawMaterialOfferPriceRequest $request,
        string $offer,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->recordOfferPriceEvent(
            $request->user(),
            $request->attributes->get('operating_location'),
            $offer,
            $request->validated(),
            $request->idempotencyKey(),
        ), 201);
    }

    public function exchangeRate(
        RawMaterialExchangeRateQueryRequest $request,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        $input = $request->validated();

        return response()->json($service->exchangeRate(
            $request->user(),
            $request->attributes->get('operating_location'),
            $input['currency_code'],
            $input['operation_date'],
        ));
    }

    public function syncExchangeRate(
        RawMaterialExchangeRateRequest $request,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        $input = $request->validated();

        return response()->json($service->syncNbpRate(
            $request->user(),
            $request->attributes->get('operating_location'),
            $input['currency_code'],
            $input['operation_date'],
            $request->idempotencyKey(),
        ), 201);
    }

    public function overrideExchangeRate(
        RawMaterialExchangeRateOverrideRequest $request,
        string $rate,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        $input = $request->validated();

        return response()->json($service->overrideExchangeRate(
            $request->user(),
            $request->attributes->get('operating_location'),
            $rate,
            $input['pln_per_quote_unit'],
            $input['reason_code'],
            $request->idempotencyKey(),
        ), 201);
    }

    public function referencePrices(
        RawMaterialReferencePriceQueryRequest $request,
        string $material,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->referencePrices(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('as_of'),
        ));
    }

    public function storeReferencePrice(
        RawMaterialReferencePriceRequest $request,
        string $material,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->setReferencePrice(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated(),
            $request->idempotencyKey(),
        ), 201);
    }

    public function referencePriceRestatements(
        Request $request,
        string $material,
        string $event,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->referencePriceRestatements(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $event,
        ));
    }

    public function storeReferencePriceRestatement(
        RawMaterialRestatementRequest $request,
        string $material,
        string $event,
        RawMaterialCommercialService $service,
    ): JsonResponse {
        return response()->json($service->createRestatement(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $event,
            $request->validated(),
            $request->idempotencyKey(),
        ), 201);
    }

    public function index(ListRawMaterialsRequest $request, RawMaterialService $service): JsonResponse
    {
        return response()->json($service->list(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        ));
    }

    public function shares(
        Request $request,
        string $material,
        RawMaterialLocationShareAdministration $administration,
    ): JsonResponse {
        return response()->json($administration->list(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
        ));
    }

    public function createShare(
        RawMaterialShareRequest $request,
        string $material,
        RawMaterialLocationShareAdministration $administration,
    ): JsonResponse {
        return response()->json($administration->share(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('location_id'),
        ), 201);
    }

    public function bulkShareLocations(
        RawMaterialBulkShareLocationsRequest $request,
        string $material,
        RawMaterialLocationShareAdministration $administration,
    ): JsonResponse {
        $result = $administration->bulkShareToLocations(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('location_ids'),
        );

        return response()->json($result, $result['outcome'] === 'REJECTED' ? 422 : 200);
    }

    public function bulkShareMaterials(
        RawMaterialBulkShareMaterialsRequest $request,
        RawMaterialLocationShareAdministration $administration,
    ): JsonResponse {
        $result = $administration->bulkShareMaterialsToLocation(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated('location_id'),
            $request->validated('material_ids'),
        );

        return response()->json($result, $result['outcome'] === 'REJECTED' ? 422 : 200);
    }

    public function revokeShare(
        RawMaterialShareRevocationRequest $request,
        string $material,
        string $location,
        RawMaterialLocationShareAdministration $administration,
    ): JsonResponse {
        return response()->json($administration->revoke(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $location,
            $request->validated('reason'),
        ));
    }

    public function show(Request $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->show(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
        )]);
    }

    public function revisions(Request $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->revisions(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
        )]);
    }

    public function allergenCatalog(Request $request, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->allergenCatalog(
            $request->user(),
            $request->attributes->get('operating_location'),
        )]);
    }

    public function revisionStage2d(
        Request $request,
        string $material,
        string $revision,
        RawMaterialService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->revisionStage2d(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $revision,
        )]);
    }

    public function updateDraftAllergenDeclaration(
        RawMaterialAllergenDeclarationRequest $request,
        string $material,
        RawMaterialService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->reviseAllergenDeclaration(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated(),
            $request->header('If-Match'),
        )]);
    }

    public function updateDraftStorageVariants(
        RawMaterialStorageVariantsRequest $request,
        string $material,
        RawMaterialService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->reviseStorageVariants(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('variants'),
            $request->header('If-Match'),
        )]);
    }

    public function store(RawMaterialDataRequest $request, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->create(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        )], 201);
    }

    public function updateDraft(RawMaterialDataRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->revise(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated(),
            $request->header('If-Match'),
        )]);
    }

    public function submit(Request $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->submit(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->header('If-Match'),
        )]);
    }

    public function approve(RawMaterialApprovalRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->approve(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('reason') ?? '',
            $request->header('If-Match'),
        )]);
    }

    public function returnForChanges(RawMaterialReasonRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->returnForChanges(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('reason'),
            $request->header('If-Match'),
        )]);
    }

    public function block(RawMaterialReasonRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->block(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('reason'),
            $request->header('If-Match'),
        )]);
    }

    public function unblock(RawMaterialReasonRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->unblock(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('reason'),
            $request->header('If-Match'),
        )]);
    }

    public function archive(RawMaterialReasonRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->archive(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('reason'),
            $request->header('If-Match'),
        )]);
    }

    public function restore(RawMaterialReasonRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->restore(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('reason'),
            $request->header('If-Match'),
        )]);
    }

    public function categories(Request $request, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->categories(
            $request->user(),
            $request->attributes->get('operating_location'),
        )]);
    }

    public function storeCategory(RawMaterialCategoryRequest $request, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->createCategory(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        )], 201);
    }

    public function renameCategory(
        RawMaterialCategoryRenameRequest $request,
        string $category,
        RawMaterialService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->renameCategory(
            $request->user(),
            $request->attributes->get('operating_location'),
            $category,
            $request->validated('name'),
        )]);
    }

    public function grantPermission(
        RawMaterialGrantRequest $request,
        User $user,
        OrganizationRawMaterialPermissionAdministration $service,
    ): JsonResponse {
        return response()->json(['data' => $service->grant(
            $request->user(),
            $request->attributes->get('operating_location'),
            $user,
            RawMaterialPermission::from($request->validated('permission')),
            $request->validated('reason'),
            $request->validated('valid_from'),
            $request->validated('valid_until'),
        )], 201);
    }

    public function revokePermission(
        RawMaterialReasonRequest $request,
        string $grant,
        OrganizationRawMaterialPermissionAdministration $service,
    ): JsonResponse {
        $service->revoke(
            $request->user(),
            $request->attributes->get('operating_location'),
            $grant,
            $request->validated('reason'),
        );

        return response()->json(['data' => null]);
    }
}
