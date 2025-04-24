<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\GoogleCalendarController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// Rutas para Google Calendar API
Route::prefix('google-calendar')->group(function () {
    // Autenticación
    Route::get('/auth', [GoogleCalendarController::class, 'getAuthUrl']);
    Route::get('/new-auth', [GoogleCalendarController::class, 'getNewAuthFlow']);
    Route::post('/token', [GoogleCalendarController::class, 'getToken']);
    Route::post('/refresh-token', [GoogleCalendarController::class, 'refreshToken']);
    Route::get('/check-token', [GoogleCalendarController::class, 'checkToken']);

    // Calendarios
    Route::get('/calendars', [GoogleCalendarController::class, 'listCalendars']);
    Route::get('/calendars-fresh', [GoogleCalendarController::class, 'listCalendarsFresh']);

    // Eventos
    Route::get('/events', [GoogleCalendarController::class, 'listEvents']);
    Route::get('/events/by-date', [GoogleCalendarController::class, 'listEventsByDate']);
    Route::post('/events', [GoogleCalendarController::class, 'createEvent']);
    Route::get('/events/{eventId}', [GoogleCalendarController::class, 'getEvent']);
    Route::put('/events/{eventId}', [GoogleCalendarController::class, 'updateEvent']);
    Route::delete('/events/{eventId}', [GoogleCalendarController::class, 'deleteEvent']);
});
