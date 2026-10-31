<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GroupController;


Route::prefix('v1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
   Route::get('/groups', [GroupController::class, 'index']);
    Route::post('/groups', [GroupController::class, 'store']);
    Route::get('/groups/{group}', [GroupController::class, 'show']);
    Route::get('/groups/{group}/members', [GroupController::class, 'members']);
    Route::post('/groups/{group}/members', [GroupController::class, 'addMember']);
    Route::get('/groups/{group}/balances', [GroupController::class, 'balance']);
    Route::post('/groups/{group}/contributions', [GroupController::class, 'storeContribution']);
    Route::post('/groups/{group}/contributions/{contribution}/corrections', [GroupController::class, 'storeCorrection']);
    Route::post('/groups/{group}/start', [GroupController::class, 'start']);
    Route::post('/groups/{group}/payouts', [GroupController::class, 'storePayout']);

    Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
    
});
});

