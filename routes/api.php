<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TailorController;

Route::post('/tailor',        [TailorController::class, 'tailor']);
Route::post('/preview',       [TailorController::class, 'preview']);
Route::post('/download/pdf',  [TailorController::class, 'downloadPdf']);
Route::post('/download/word', [TailorController::class, 'downloadWord']);
