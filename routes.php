<?php

use Illuminate\Support\Facades\Route;
use Mercator\Secret\Controllers\SignedFileController;

Route::get('secret-download', [SignedFileController::class, 'download'])
    ->name('mercator.secret.download');
