<?php

use App\Jobs\FetchUpcomingMoviesJob;
use App\Jobs\ShowSeat\UnlockShowSeatsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule::job(new FetchUpcomingMoviesJob(config('services.language_code.english')))->everyFiveMinutes();
// Fetch upcoming 'Hindi' movies
Schedule::job(new FetchUpcomingMoviesJob('hi'))->everyFifteenMinutes();
Schedule::job(new UnlockShowSeatsJob())
    ->everyMinute();
