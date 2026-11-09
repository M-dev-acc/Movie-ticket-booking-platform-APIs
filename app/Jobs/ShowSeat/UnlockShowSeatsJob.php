<?php

namespace App\Jobs\ShowSeat;

use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\ShowSeat;
use App\Services\BookingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Runs every minute. Ends bookings whose seat hold has run out and frees their seats.
 *
 * Its cost depends on how many holds expired in this run, not on the size of the tables.
 * A backlog is worked off in bounded batches over several runs.
 */
class UnlockShowSeatsJob implements ShouldQueue
{
    use Queueable;

    private const MAX_BOOKINGS_PER_RUN = 5000;

    private const CHUNK_SIZE = 500;

    public int $tries = 3;

    public int $timeout = 120; // the queue worker's --timeout must be larger than this

    public array $backoff = [30, 60, 120]; // seconds between retries

    /**
     * Only one run at a time, even across several queue workers.
     * (The scheduler's withoutOverlapping() only guards the moment of dispatching.)
     * dontRelease(): a second run is dropped; the next minute's run picks the work up.
     * expireAfter(): safety net if a worker dies while holding the lock.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('unlock-show-seats'))->dontRelease()->expireAfter(300)];
    }

    public function handle(BookingService $bookings): void
    {
        // 1. Bookings with an expired hold, found through their pending lines (bounded).
        $bookingIds = BookingSeat::query()
            ->where('status', BookingSeat::STATUS_PENDING)
            ->whereIn('show_seat_id', ShowSeat::expiredLockedSeats()->select('id'))
            ->distinct()
            ->limit(self::MAX_BOOKINGS_PER_RUN)
            ->pluck('booking_id');

        $released = 0;

        foreach ($bookingIds->chunk(self::CHUNK_SIZE) as $chunk) {
            try {
                $released += $bookings->releaseHolds(
                    $chunk->all(),
                    Booking::STATUS_EXPIRED,
                    skipLocked: true,
                );
            } catch (\Throwable $e) {
                // A batch is one transaction, so it rolled back as a whole.
                // Retry its bookings one by one to isolate the bad one.
                Log::warning('UnlockShowSeatsJob: batch failed, retrying one by one.', [
                    'count' => $chunk->count(),
                    'error' => $e->getMessage(),
                ]);

                foreach ($chunk as $bookingId) {
                    try {
                        $released += $bookings->releaseHold((int) $bookingId, Booking::STATUS_EXPIRED) ? 1 : 0;
                    } catch (\Throwable $e) {
                        Log::error('UnlockShowSeatsJob: could not release booking.', [
                            'booking_id' => $bookingId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // 2. Seats still locked and expired that no active line points at.
        //    A seat with a pending OR confirmed line is never touched here.
        $orphans = ShowSeat::expiredLockedSeats()
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('booking_seats')
                    ->whereColumn('booking_seats.show_seat_id', 'show_seats.id')
                    ->whereIn('booking_seats.status', [
                        BookingSeat::STATUS_PENDING,
                        BookingSeat::STATUS_CONFIRMED,
                    ]);
            })
            ->update([
                'status' => ShowSeat::STATUS_AVAILABLE,
                'locked_until' => null,
            ]);

        if ($released > 0 || $orphans > 0) {
            Log::info('UnlockShowSeatsJob: expired holds released.', [
                'bookings' => $released,
                'orphan_seats' => $orphans,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('UnlockShowSeatsJob: job failed permanently.', [
            'error' => $exception->getMessage(),
        ]);
    }
}