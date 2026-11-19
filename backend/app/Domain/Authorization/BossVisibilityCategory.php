<?php

namespace App\Domain\Authorization;

enum BossVisibilityCategory: string
{
    case RawSuppliers = 'RAW_SUPPLIERS';
    case PurchaseFinancials = 'PURCHASE_FINANCIALS';
    case Foodcost = 'FOODCOST';
    case ProductsRecipes = 'PRODUCTS_RECIPES';
    case Production = 'PRODUCTION';
    case WarehouseInventory = 'WAREHOUSE_INVENTORY';
    case Orders = 'ORDERS';
    case ReportsAnalytics = 'REPORTS_ANALYTICS';

    public function label(): string
    {
        return match ($this) {
            self::RawSuppliers => 'Surowce i dostawcy',
            self::PurchaseFinancials => 'Ceny zakupu i informacje finansowe',
            self::Foodcost => 'Koszty receptur',
            self::ProductsRecipes => 'Produkty i receptury',
            self::Production => 'Produkcja',
            self::WarehouseInventory => 'Magazyny i zapasy',
            self::Orders => 'Zamówienia',
            self::ReportsAnalytics => 'Raporty i zestawienia',
        };
    }
}
