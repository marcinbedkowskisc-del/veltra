<?php

use App\Domain\RawMaterials\Documents\RawMaterialUploadService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('raw-materials:cleanup-uploads', function (RawMaterialUploadService $uploads): void {
    $this->info('Usunięto wygasłe uploady RAW: '.$uploads->cleanupExpired());
})->purpose('Usuwanie wyłącznie wygasłych, nieprzypisanych uploadów RAW');

Schedule::command('raw-materials:cleanup-uploads')->hourly()->withoutOverlapping();
