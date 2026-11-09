<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

// Breeze Blade routes with the same URLs, names, guards and limits as 1.6.0.
Route::middleware('guest:merchant,staff')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login-ip')->name('merchant.login.store');
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:registration')->name('register.store');
    Route::get('/staff/login', [AuthenticatedSessionController::class, 'create'])->name('staff.login');
    Route::post('/staff/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login-ip')->name('staff.login.store');
});
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:merchant')->name('merchant.logout');
Route::post('/staff/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:staff')->name('staff.logout');
