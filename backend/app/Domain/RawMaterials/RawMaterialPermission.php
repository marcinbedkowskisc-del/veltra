<?php

namespace App\Domain\RawMaterials;

enum RawMaterialPermission: string
{
    case View = 'RAW_MATERIALS_VIEW';
    case Create = 'RAW_MATERIALS_CREATE';
    case Edit = 'RAW_MATERIALS_EDIT';
    case Submit = 'RAW_MATERIALS_SUBMIT';
    case Review = 'RAW_MATERIALS_REVIEW';
    case Approve = 'RAW_MATERIALS_APPROVE';
    case Block = 'RAW_MATERIALS_BLOCK';
    case Unblock = 'RAW_MATERIALS_UNBLOCK';
    case Archive = 'RAW_MATERIALS_ARCHIVE';
    case Restore = 'RAW_MATERIALS_RESTORE';
    case DeleteDraft = 'RAW_MATERIALS_DELETE_DRAFT';
    case CategoryManage = 'RAW_MATERIALS_CATEGORY_MANAGE';
    case SupplierView = 'RAW_MATERIALS_SUPPLIER_VIEW';
    case SupplierManage = 'RAW_MATERIALS_SUPPLIER_MANAGE';
    case PurchasingCostsView = 'RAW_MATERIALS_PURCHASING_COSTS_VIEW';
    case PurchasingCostsManage = 'RAW_MATERIALS_PURCHASING_COSTS_MANAGE';
    case ReferencePriceView = 'RAW_MATERIALS_REFERENCE_PRICE_VIEW';
    case ReferencePriceManage = 'RAW_MATERIALS_REFERENCE_PRICE_MANAGE';
    case ExchangeRateView = 'RAW_MATERIALS_EXCHANGE_RATE_VIEW';
    case ExchangeRateManage = 'RAW_MATERIALS_EXCHANGE_RATE_MANAGE';
    case AllergenManage = 'RAW_MATERIALS_ALLERGEN_MANAGE';
    case StorageManage = 'RAW_MATERIALS_STORAGE_MANAGE';
    case AuditView = 'RAW_MATERIALS_AUDIT_VIEW';
    case PermissionDelegate = 'RAW_MATERIALS_PERMISSION_DELEGATE';

    public function delegable(): bool
    {
        return ! in_array($this, [self::PermissionDelegate, self::DeleteDraft], true);
    }

    public function requiresPrimaryCatalogContext(): bool
    {
        return in_array($this, [
            self::Create,
            self::Edit,
            self::Submit,
            self::Review,
            self::Approve,
            self::Block,
            self::Unblock,
            self::Archive,
            self::Restore,
            self::DeleteDraft,
            self::CategoryManage,
            self::AllergenManage,
            self::StorageManage,
        ], true);
    }
}
