<?php

use App\Http\Controllers\{
    AuthenticatedSessionController,
    BookingController,
    MovieController,
    MovieShowController,
    PaymentController,
    SeatController,
    TheaterController,
    UserRegistrationController,
    WebhookController,
};
use Illuminate\Support\Facades\Route;

Route::post('/register', UserRegistrationController::class);
Route::post('/login', [AuthenticatedSessionController::class, 'store']);

Route::post('/webhooks/razorpay', WebhookController::class);

Route::controller(MovieController::class)
    ->prefix('movies')
    ->group(function () {
        Route::get('/latest', [MovieController::class, 'index']);
        Route::get('/upcoming', [MovieController::class, 'upcoming']);
        Route::get('/search', [MovieController::class, 'search']);
        Route::get('/{id}', [MovieController::class, 'show'])
        ->whereNumber('id');
    });

Route::group([
    'middleware' => "auth:sanctum",
    'prefix' => 'v1'
], function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    Route::get('/user', [AuthenticatedSessionController::class, 'loggedUser']);

    require __DIR__ . '/admin.php';
    require __DIR__ . '/owner.php';


    Route::controller(PaymentController::class)
        ->prefix('payments')
        ->group(function () {
            Route::post('/initiate', 'initiate');
            Route::post('/verify', 'verify');
        });

    Route::group([
        'middleware' => "role:user",
        'as' => "user"
    ], function () {

        Route::controller(TheaterController::class)
            ->prefix('theater')
            ->scopeBindings()
            ->group(
                function () {
                    Route::get('/', 'index');
                    Route::get('/{theater}', 'show')
                        ->middleware('permission:Read Theater');
                }
            );

        Route::controller(MovieShowController::class)
            ->prefix('shows')
            ->scopeBindings()
            ->group(function () {
                Route::get('/', 'index');
                Route::get('/{movie_show}', 'show')
                    ->middleware('permission:Read Movie Show');
            });

        Route::controller(SeatController::class)
            ->prefix('shows/{movie_show}/seats')
            ->scopeBindings()
            ->group(function () {
                Route::get('/', 'index');
            });

        Route::controller(BookingController::class)
            ->prefix('bookings')
            ->as('.bookings')
            ->scopeBindings()
            ->group(function () {
                Route::get('/', 'index');
                Route::post('/confirm', 'store')
                    ->name('.confirm');
                    // ->middleware('permission:Create Booking');
                Route::get('/{booking}', 'show');
                    // ->middleware('permission:Read Booking');
                Route::delete('/{booking}/cancel', 'cancel');
                    // ->middleware('permission:Delete Booking');
            });
    });

});
