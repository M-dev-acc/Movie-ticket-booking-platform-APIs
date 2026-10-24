<?php

namespace Database\Seeders;

use App\Jobs\ShowSeat\PopulateShowSeatsJob;
use App\Models\Movie;
use App\Models\MovieShow;
use App\Models\Screen;
use App\Models\Seat;
use App\Models\Theater;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo data: one movie, one screen with 20 seats, and one show tomorrow at 18:00.
 *
 * After this runs, the show already has its 20 show_seats rows, so you can book a seat
 * straight away (no queue worker needed).
 *
 * Safe to run more than once: every record is looked up before it is created.
 * Never runs in production.
 */
class DemoShowSeeder extends Seeder
{
    // Far above real TMDB ids, so the TMDB sync can never collide with this row.
    private const MOVIE_EXTERNAL_ID = 900000001;

    private const SEATS_PER_ROW = 5;

    // 4 rows x 5 seats = 20 seats, with all three seat types.
    private const LAYOUT = [
        'A' => Seat::TYPE_STANDARD,
        'B' => Seat::TYPE_STANDARD,
        'C' => Seat::TYPE_PREMIUM,
        'D' => Seat::TYPE_RECLINER,
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $theater = Theater::query()->first()
            ?? throw new RuntimeException('No theater found. Run TheaterSeeder first.');

        $movie = $this->movie();
        $screen = $this->screen($theater);
        $this->seats($screen);
        $show = $this->show($movie, $screen);

        $this->ensureSeatMatrix($show, $screen);
    }

    private function movie(): Movie
    {
        return Movie::firstOrCreate(
            ['external_id' => self::MOVIE_EXTERNAL_ID],
            [
                'title' => 'Demo Movie',
                'release_date' => now()->subDays(7)->toDateString(),
                'genres' => ['Drama'],
                'rating' => 7.5,
                'original_language' => 'en',
                'overview' => 'A demo movie for local development.',
            ],
        );
    }

    private function screen(Theater $theater): Screen
    {
        return Screen::firstOrCreate(
            ['theater_id' => $theater->id, 'name' => 'Demo Screen'],
            [
                'type' => Screen::TYPE_2D,
                'capacity' => count(self::LAYOUT) * self::SEATS_PER_ROW,
                'status' => true,
            ],
        );
    }

    private function seats(Screen $screen): void
    {
        foreach (self::LAYOUT as $row => $type) {
            for ($number = 1; $number <= self::SEATS_PER_ROW; $number++) {
                Seat::firstOrCreate(
                    ['screen_id' => $screen->id, 'row' => $row, 'number' => $number],
                    ['type' => $type, 'is_active' => true],
                );
            }
        }
    }

    private function show(Movie $movie, Screen $screen): MovieShow
    {
        $scheduledAt = now()->addDay()->setTime(18, 0);

        // Creating a show makes MovieShowObserver queue PopulateShowSeatsJob.
        // Run the queue inline for this one call so the seat rows exist when we finish.
        // (Do not use MovieShow::withoutEvents(): the model's `saving` hook sets end_at.)
        $previousQueue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            return MovieShow::firstOrCreate(
                ['screen_id' => $screen->id, 'scheduled_at' => $scheduledAt],
                ['movie_id' => $movie->id, 'duration' => 120, 'price' => 250],
            );
        } finally {
            config(['queue.default' => $previousQueue]);
        }
    }

    private function ensureSeatMatrix(MovieShow $show, Screen $screen): void
    {
        $expected = Seat::where('screen_id', $screen->id)->where('is_active', true)->count();

        // Covers a show that already existed without seat rows. The job is safe to repeat.
        if ($show->seats()->count() < $expected) {
            PopulateShowSeatsJob::dispatchSync($show);
        }

        $actual = $show->seats()->count();

        if ($actual !== $expected) {
            throw new RuntimeException(
                "Expected {$expected} show_seats for show {$show->id}, found {$actual}. "
                . 'Check PopulateShowSeatsJob and MovieShowObserver.'
            );
        }

        $this->command?->info(
            "Demo show #{$show->id} ready: {$actual} seats, price {$show->price}, starts {$show->scheduled_at}."
        );
    }
}