<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ReservationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes - Get event information
Route::prefix('events')->group(function () {
    Route::get('/', [EventController::class, 'index']);
    Route::get('/{eventId}', [EventController::class, 'show']);
});

// Protected routes - Require authentication
Route::middleware('auth:sanctum')->group(function () {
    
    // Reserve a ticket
    Route::post('events/{eventId}/reserve', [ReservationController::class, 'reserve']);
    
    // Get user's reservations
    Route::get('reservations', [ReservationController::class, 'index']);
    
    // Purchase a reserved ticket
    Route::post('reservations/{reservationId}/purchase', [ReservationController::class, 'purchase']);
    
    // Cancel a reservation
    Route::delete('reservations/{reservationId}', [ReservationController::class, 'cancel']);
    
    // Get authenticated user info
    Route::get('user', function (Request $request) {
        return $request->user();
    });
});

