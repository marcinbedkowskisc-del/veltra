<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raw_materials_commercial_product_revision_validate() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                unit_factor numeric;
                material_state varchar(16);
                approved_revision uuid;
                product_state varchar(16);
                supplier_state varchar(16);
            BEGIN
                PERFORM id FROM organizations WHERE id = NEW.organization_id FOR UPDATE;
                SELECT products.lifecycle_state, suppliers.lifecycle_state
                    INTO product_state, supplier_state
                    FROM supplier_products products
                    JOIN organization_suppliers suppliers
                        ON suppliers.id = products.supplier_id
                        AND suppliers.organization_id = products.organization_id
                    WHERE products.id = NEW.supplier_product_id
                        AND products.organization_id = NEW.organization_id;
                IF product_state IS DISTINCT FROM 'ACTIVE' OR supplier_state IS DISTINCT FROM 'ACTIVE' THEN
                    RAISE EXCEPTION 'Supplier product revisions require an active product and supplier'
                        USING ERRCODE = '23514';
                END IF;
                SELECT lifecycle_state, approved_revision_id INTO material_state, approved_revision
                    FROM raw_materials WHERE id = NEW.raw_material_id
                        AND organization_id = NEW.organization_id FOR SHARE;
                IF material_state <> 'ACTIVE' OR approved_revision IS DISTINCT FROM NEW.raw_material_revision_id THEN
                    RAISE EXCEPTION 'Supplier product requires the current approved raw material revision'
                        USING ERRCODE = '23514';
                END IF;
                SELECT to_base_factor INTO unit_factor FROM raw_material_revision_units
                    WHERE id = NEW.raw_material_revision_unit_id
                        AND revision_id = NEW.raw_material_revision_id
                        AND organization_id = NEW.organization_id
                        AND unit_code = NEW.content_unit_code;
                IF unit_factor IS NULL THEN
                    RAISE EXCEPTION 'Supplier product unit does not belong to the approved revision'
                        USING ERRCODE = '23514';
                END IF;
                NEW.to_base_factor_snapshot := unit_factor;
                NEW.base_quantity_snapshot := raw_materials_round_half_even_v1(
                    NEW.content_quantity * unit_factor, 14);
                NEW.content_hash := encode(sha256(convert_to(concat_ws('|', NEW.supplier_product_id, NEW.revision_no,
                    NEW.raw_material_revision_id, NEW.raw_material_revision_unit_id, NEW.supplier_sku,
                    NEW.gtin, NEW.product_name, NEW.purchase_unit_code, NEW.content_quantity,
                    NEW.content_unit_code, NEW.to_base_factor_snapshot, NEW.base_quantity_snapshot), 'UTF8')), 'hex');
                RETURN NEW;
            END
            $$;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Nie można wycofać wymogu aktywności dostawcy i produktu dla nowych rewizji.');
    }
};
