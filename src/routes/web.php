<?php

use App\Http\Controllers\PublicProductController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health/ready', function () {
    DB::select('select 1');

    return response()->json(['status' => 'ready']);
});

Route::get('/p/{alias}/productos/{slug}', [PublicProductController::class, 'show'])
    ->where(['alias' => '[A-Za-z0-9_-]+', 'slug' => '[A-Za-z0-9-]+'])
    ->name('products.public.show');

Route::get('/', function () {
    return view('welcome');
});
