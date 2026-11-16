<?php

use App\Http\Controllers\AdmsController;
use Illuminate\Support\Facades\Route;

// Endpoints the terminal calls in push mode (Cloud Server / ADMS). No sessions or CSRF:
// the terminal identifies itself by serial number, which must be on the allowed list.
Route::prefix('iclock')->group(function () {
    Route::get('cdata', [AdmsController::class, 'handshake']);
    Route::post('cdata', [AdmsController::class, 'upload']);
    Route::get('getrequest', [AdmsController::class, 'poll']);
    Route::post('devicecmd', [AdmsController::class, 'commandResult']);
    Route::get('ping', [AdmsController::class, 'poll']);
});
