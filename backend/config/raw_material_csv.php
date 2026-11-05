<?php

return [
    'max_file_bytes' => (int) env('RAW_MATERIAL_CSV_MAX_FILE_BYTES', 5 * 1024 * 1024),
    'max_rows' => (int) env('RAW_MATERIAL_CSV_MAX_ROWS', 5000),
    'preview_ttl_minutes' => (int) env('RAW_MATERIAL_CSV_PREVIEW_TTL_MINUTES', 30),
    'export_max_rows' => (int) env('RAW_MATERIAL_CSV_EXPORT_MAX_ROWS', 5000),
    'export_max_bytes' => (int) env('RAW_MATERIAL_CSV_EXPORT_MAX_BYTES', 5 * 1024 * 1024),
    'export_ttl_minutes' => (int) env('RAW_MATERIAL_CSV_EXPORT_TTL_MINUTES', 60),
];
