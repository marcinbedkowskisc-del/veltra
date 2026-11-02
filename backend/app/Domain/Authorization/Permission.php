<?php

namespace App\Domain\Authorization;

enum Permission: string
{
    case LocationView = 'LOCATION_VIEW';
    case StructureManage = 'STRUCTURE_MANAGE';
    case PersonnelManage = 'PERSONNEL_MANAGE';
    case RecipesView = 'RECIPES_VIEW';
    case QuantityCalculate = 'QUANTITY_CALCULATE';
    case FoodcostView = 'FOODCOST_VIEW';
    case PurchasePricesView = 'PURCHASE_PRICES_VIEW';
    case ReportsView = 'REPORTS_VIEW';
    case ReportsExport = 'REPORTS_EXPORT';
    case TemperatureRegister = 'TEMPERATURE_REGISTER';
    case CleaningEntryCreate = 'CLEANING_ENTRY_CREATE';
    case LossReportCreate = 'LOSS_REPORT_CREATE';
    case InventoryCountEntry = 'INVENTORY_COUNT_ENTRY';

    public function module(): ?string
    {
        return match ($this) {
            self::TemperatureRegister => 'TEMPERATURES',
            self::CleaningEntryCreate => 'CLEANING',
            self::LossReportCreate => 'LOSSES',
            self::InventoryCountEntry => 'INVENTORY',
            default => null,
        };
    }

    public function special(): bool
    {
        return in_array($this, [self::FoodcostView, self::PurchasePricesView, self::ReportsView, self::ReportsExport], true);
    }
}
