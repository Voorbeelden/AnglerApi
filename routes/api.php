<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\StandingsController;
use App\Http\Controllers\Api\V1\WeighInController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1) - gedeeld tussen webapp (fetch/axios) en de mobiele app
|--------------------------------------------------------------------------
| Token-auth via Sanctum. Taal loopt via de "Accept-Language"-header
| (locale-middleware op de hele 'api'-groep) in plaats van een
| {locale}-URL-segment zoals bij de webapp.
|
| Alles hieronder hergebruikt dezelfde service-laag en policies als de
| webcontrollers (routes/web.php) - enkel JsonResponse i.p.v. Blade views,
| en altijd via App\Http\Resources\Api\V1\* i.p.v. rechtstreeks een
| Eloquent-model terug te geven (zie ClubResource voor waarom dat
| belangrijk is).
|
| Deze excerpt toont enkel de routes die horen bij de controllers in deze
| repository (auth, wegingen, klassement, hier vertaald naar auth/
| weigh-ins/standings) - de volledige v1-API omvat daarnaast nog clubs,
| visvijvers, leden, betalingen, lidgeld-vernieuwing, wedstrijdinschrijving
| en wedstrijdbeheer, bewust niet in deze excerpt opgenomen (zie de
| README). Route-paden, class- en methodenamen zijn voor deze publieke
| excerpt vertaald/hernoemd t.o.v. de originele, Nederlandstalige
| productcode - zie de README voor waarom.
*/

Route::prefix('v1')->name('api.')->group(function () {

    // Strengere rate limit dan de rest - los van de algemene throttle:api
    // hieronder, die te soepel zou zijn voor een inlogpoging.
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/verify-2fa', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:two-factor');

    Route::middleware(['auth:sanctum', 'ensure-member-account', 'throttle:api'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // ================= STANDINGS =================
        Route::get('/clubs/{club}/standings', [StandingsController::class, 'index']);
        Route::get('/clubs/{club}/standings/members/{user}', [StandingsController::class, 'memberDetail']);

        // ================= WEIGH-INS (kernscenario mobiele app) =================
        // Extra, strengere limiet dan de algemene 'api'-limiter: wegingen
        // worden kort na elkaar door meerdere mensen op een wedstrijddag
        // ingevoerd, maar 1 verkeerd geconfigureerde app-lus mag nooit de
        // hele club kunnen platleggen.
        Route::middleware('throttle:weigh-ins')->group(function () {
            Route::get('/competitions/{competition}/weigh-ins', [WeighInController::class, 'index']);
            Route::post('/competitions/{competition}/weigh-ins', [WeighInController::class, 'store']);
            // Batch-synchronisatie na een offline periode - zie WeighInController::sync().
            Route::post('/competitions/{competition}/weigh-ins/sync', [WeighInController::class, 'sync']);
        });
    });
});
