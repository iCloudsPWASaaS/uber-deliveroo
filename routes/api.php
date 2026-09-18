<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);

Route::get('/stores', [StoreController::class, 'index']);
Route::post('/stores', [StoreController::class, 'store']);
Route::get('/stores/{storeId}', [StoreController::class, 'show']);
Route::put('/stores/{storeId}', [StoreController::class, 'update']);
Route::delete('/stores/{storeId}', [StoreController::class, 'destroy']);

Route::get('/stores/{storeId}/menu', [MenuController::class, 'index']);
Route::post('/stores/{storeId}/menu', [MenuController::class, 'store']);
Route::put('/stores/{storeId}/menu', [MenuController::class, 'update']);
Route::get('/stores/{storeId}/menu/{itemId}', [MenuItemController::class, 'show']);
Route::put('/stores/{storeId}/menu/{itemId}', [MenuItemController::class, 'update']);
Route::delete('/stores/{storeId}/menu/{itemId}', [MenuItemController::class, 'destroy']);

Route::get('/stores/{storeId}/orders', [OrderController::class, 'index']);
Route::get('/stores/{storeId}/orders/{orderId}', [OrderController::class, 'show']);
Route::post('/stores/{storeId}/orders/{orderId}', [OrderController::class, 'action']);

Route::get('/stores/{storeId}/platforms', [PlatformController::class, 'index']);
Route::post('/stores/{storeId}/platforms', [PlatformController::class, 'connect']);
Route::post('/stores/{storeId}/platforms/fetch-uber-stores', [PlatformController::class, 'fetchUberStores']);
Route::post('/stores/{storeId}/platforms/fetch-deliveroo-sites', [PlatformController::class, 'fetchDeliverooSites']);

Route::get('/stores/{storeId}/status', [StatusController::class, 'show']);
Route::post('/stores/{storeId}/status', [StatusController::class, 'update']);

Route::post('/webhook/uber-eats', [WebhookController::class, 'uberEats']);
Route::post('/webhook/deliveroo', [WebhookController::class, 'deliveroo']);
