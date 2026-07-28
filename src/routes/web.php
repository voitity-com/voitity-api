<?php

use App\Http\Controllers\PublicProductController;
use Illuminate\Support\Facades\Route;

Route::get('/p/{alias}/productos/{slug}', [PublicProductController::class, 'show'])
    ->where(['alias' => '[A-Za-z0-9_-]+', 'slug' => '[A-Za-z0-9-]+'])
    ->name('products.public.show');

Route::get('/', function () {
    return view('welcome');
});
