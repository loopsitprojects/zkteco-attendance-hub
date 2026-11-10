<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Everyone signed in can view and export.
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance');
    Route::get('/attendance/data', [AttendanceController::class, 'data'])->name('attendance.data');
    Route::get('/attendance/export', [AttendanceController::class, 'export'])->name('attendance.export');
    Route::get('/live/feed', [LiveController::class, 'feed'])->name('live.feed');
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees');
    Route::get('/device', [DeviceController::class, 'index'])->name('device');
    Route::get('/device/status', [DeviceController::class, 'status'])->name('device.status');
    Route::get('/account', [AccountController::class, 'edit'])->name('account');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');

    // Only administrators can change the terminal, settings or logins.
    Route::middleware('admin')->group(function () {
        Route::post('/device/sync', [DeviceController::class, 'sync'])->name('device.sync');
        Route::post('/device/time', [DeviceController::class, 'syncTime'])->name('device.time');
        Route::post('/device/voice', [DeviceController::class, 'testVoice'])->name('device.voice');
        Route::post('/device/restart', [DeviceController::class, 'restart'])->name('device.restart');

        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/test', [SettingsController::class, 'test'])->name('settings.test');

        Route::get('/users', [UserController::class, 'index'])->name('users');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
