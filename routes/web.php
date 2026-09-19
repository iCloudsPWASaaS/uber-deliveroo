<?php

use App\Http\Controllers\PlaygroundController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PlaygroundController::class, 'home'])->name('home');

Route::get('/stores', [PlaygroundController::class, 'stores'])->name('stores');
Route::post('/stores', [PlaygroundController::class, 'createStore'])->name('stores.create');

Route::get('/uber', [PlaygroundController::class, 'uber'])->name('uber');
Route::post('/uber/connect', [PlaygroundController::class, 'uberConnect'])->name('uber.connect');
Route::post('/uber/fetch-stores', [PlaygroundController::class, 'uberFetchStores'])->name('uber.fetch-stores');
Route::post('/uber/status', [PlaygroundController::class, 'uberStatus'])->name('uber.status');
Route::post('/uber/menu/pull', [PlaygroundController::class, 'uberMenuPull'])->name('uber.menu.pull');
Route::post('/uber/menu/push', [PlaygroundController::class, 'uberMenuPush'])->name('uber.menu.push');
Route::post('/uber/menu/item/save', [PlaygroundController::class, 'uberMenuItemSave'])->name('uber.menu.item.save');
Route::post('/uber/menu/item/delete', [PlaygroundController::class, 'uberMenuItemDelete'])->name('uber.menu.item.delete');
Route::post('/uber/order', [PlaygroundController::class, 'uberOrder'])->name('uber.order');

Route::get('/uber/activate', [PlaygroundController::class, 'uberActivate'])->name('uber.activate');
Route::get('/uber/oauth/callback', [PlaygroundController::class, 'uberOAuthCallback'])->name('uber.oauth.callback');

Route::get('/deliveroo', [PlaygroundController::class, 'deliveroo'])->name('deliveroo');
Route::post('/deliveroo/connect', [PlaygroundController::class, 'deliverooConnect'])->name('deliveroo.connect');
Route::post('/deliveroo/fetch-sites', [PlaygroundController::class, 'deliverooFetchSites'])->name('deliveroo.fetch-sites');
Route::post('/deliveroo/status', [PlaygroundController::class, 'deliverooStatus'])->name('deliveroo.status');
Route::post('/deliveroo/menu/pull', [PlaygroundController::class, 'deliverooMenuPull'])->name('deliveroo.menu.pull');
Route::post('/deliveroo/menu/push', [PlaygroundController::class, 'deliverooMenuPush'])->name('deliveroo.menu.push');
Route::post('/deliveroo/order', [PlaygroundController::class, 'deliverooOrder'])->name('deliveroo.order');
