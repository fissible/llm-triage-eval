<?php

use App\Http\Controllers\CommandStreamController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Live-streamed artisan command output for the Commands console (auth-gated).
Route::get('/triage/command-stream', [CommandStreamController::class, 'stream'])
    ->middleware(['web', 'auth'])
    ->name('commands.stream');
