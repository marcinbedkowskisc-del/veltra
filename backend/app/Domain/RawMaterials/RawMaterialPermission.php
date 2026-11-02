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
    case CategoryManage = 'RAW_MATERIALS_CATEGORY_MANAGE';
    case AuditView = 'RAW_MATERIALS_AUDIT_VIEW';
    case PermissionDelegate = 'RAW_MATERIALS_PERMISSION_DELEGATE';

    public function delegable(): bool
    {
        return $this !== self::PermissionDelegate;
    }
}
