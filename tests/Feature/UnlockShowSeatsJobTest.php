<?php

namespace Tests\Feature;

use App\Jobs\ShowSeat\UnlockShowSeatsJob;
use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\MovieShow;
use App\Models\Seat;
use App\Models\ShowSeat;
use App\Models\Theater;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\DemoShowSeeder;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnlockShowSeatsJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MovieShow $show;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        Theater::factory()->create();
        $this->seed(DemoShowSeeder::class);
        $this->show = MovieShow::firstOrFail();
    }

    #[Test]
    public function it_expires_old_holds_but_leaves_live_holds_untouched(): void
    {
        $showSeats = $this->show->seats()->orderBy('id')->take(2)->get();
        $expiredBooking = $this->createHold($showSeats[0], now()->subMinute());
        $liveBooking = $this->createHold(
            $showSeats[1],
            now()->addMinute(),
            Booking::STATUS_PAYMENT_PENDING,
        );

        $this->runJob();

        $this->assertSame(Booking::STATUS_EXPIRED, $expiredBooking->fresh()->status);
        $this->assertSame(
            BookingSeat::STATUS_CANCELLED,
            $expiredBooking->bookingSeats()->firstOrFail()->status,
        );
        $this->assertSame(ShowSeat::STATUS_AVAILABLE, $showSeats[0]->fresh()->status);
        $this->assertNull($showSeats[0]->fresh()->locked_until);

        $this->assertSame(Booking::STATUS_PAYMENT_PENDING, $liveBooking->fresh()->status);
        $this->assertSame(
            BookingSeat::STATUS_PENDING,
            $liveBooking->bookingSeats()->firstOrFail()->status,
        );
        $this->assertSame(ShowSeat::STATUS_LOCKED, $showSeats[1]->fresh()->status);
        $this->assertTrue($showSeats[1]->fresh()->locked_until->isFuture());
    }

    #[Test]
    public function it_is_safe_to_run_again_after_expiring_a_hold(): void
    {
        $showSeat = $this->show->seats()->firstOrFail();
        $booking = $this->createHold($showSeat, now()->subMinute());

        $this->runJob();
        $this->runJob();

        $this->assertSame(Booking::STATUS_EXPIRED, $booking->fresh()->status);
        $this->assertSame(
            BookingSeat::STATUS_CANCELLED,
            $booking->bookingSeats()->firstOrFail()->status,
        );
        $this->assertSame(ShowSeat::STATUS_AVAILABLE, $showSeat->fresh()->status);
        $this->assertNull($showSeat->fresh()->locked_until);
    }

    #[Test]
    public function it_does_not_unlock_an_expired_seat_with_a_confirmed_booking_line(): void
    {
        $showSeat = $this->show->seats()->firstOrFail();
        $booking = $this->createHold(
            $showSeat,
            now()->subMinute(),
            Booking::STATUS_CONFIRMED,
            BookingSeat::STATUS_CONFIRMED,
        );

        $this->runJob();

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
        $this->assertSame(
            BookingSeat::STATUS_CONFIRMED,
            $booking->bookingSeats()->firstOrFail()->status,
        );
        $this->assertSame(ShowSeat::STATUS_LOCKED, $showSeat->fresh()->status);
        $this->assertTrue($showSeat->fresh()->locked_until->isPast());
    }

    #[Test]
    public function it_releases_expired_holds_across_batches_larger_than_500_bookings(): void
    {
        $showSeats = $this->createShowSeats(501);
        $timestamp = now();
        $bookingRows = [];
        $bookingCodes = [];

        foreach ($showSeats as $showSeat) {
            $code = (string) Str::uuid();
            $bookingCodes[] = $code;
            $bookingRows[] = [
                'user_id' => $this->user->id,
                'show_id' => $showSeat->show_id,
                'movie_id' => $this->show->movie_id,
                'code' => $code,
                'status' => Booking::STATUS_RESERVED,
                'total_amount' => 250,
                'booked_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        DB::table('bookings')->insert($bookingRows);

        $bookingIdByCode = DB::table('bookings')
            ->whereIn('code', $bookingCodes)
            ->pluck('id', 'code');
        $bookingSeatRows = [];
        foreach ($showSeats as $index => $showSeat) {
            $bookingSeatRows[] = [
                'booking_id' => $bookingIdByCode[$bookingCodes[$index]],
                'show_seat_id' => $showSeat->id,
                'price_paid' => 250,
                'status' => BookingSeat::STATUS_PENDING,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        DB::table('booking_seats')->insert($bookingSeatRows);

        $this->runJob();

        $this->assertSame(
            501,
            Booking::whereIn('code', $bookingCodes)
                ->where('status', Booking::STATUS_EXPIRED)
                ->count(),
        );
        $this->assertSame(
            501,
            BookingSeat::whereIn('booking_id', $bookingIdByCode->values())
                ->where('status', BookingSeat::STATUS_CANCELLED)
                ->count(),
        );
        $this->assertSame(
            501,
            ShowSeat::whereIn('id', $showSeats->pluck('id'))
                ->where('status', ShowSeat::STATUS_AVAILABLE)
                ->whereNull('locked_until')
                ->count(),
        );
    }

    #[Test]
    public function it_unlocks_an_expired_seat_with_no_active_booking_line(): void
    {
        $showSeat = $this->show->seats()->firstOrFail();
        $showSeat->update([
            'status' => ShowSeat::STATUS_LOCKED,
            'locked_until' => now()->subMinute(),
        ]);

        $this->runJob();

        $this->assertSame(ShowSeat::STATUS_AVAILABLE, $showSeat->fresh()->status);
        $this->assertNull($showSeat->fresh()->locked_until);
    }

    private function createHold(
        ShowSeat $showSeat,
        DateTimeInterface $lockedUntil,
        string $bookingStatus = Booking::STATUS_RESERVED,
        string $lineStatus = BookingSeat::STATUS_PENDING,
    ): Booking {
        $showSeat->update([
            'status' => ShowSeat::STATUS_LOCKED,
            'locked_until' => $lockedUntil,
        ]);

        $booking = Booking::create([
            'user_id' => $this->user->id,
            'show_id' => $showSeat->show_id,
            'movie_id' => $this->show->movie_id,
            'status' => $bookingStatus,
            'total_amount' => $showSeat->price,
        ]);

        $booking->bookingSeats()->create([
            'show_seat_id' => $showSeat->id,
            'price_paid' => $showSeat->price,
            'status' => $lineStatus,
        ]);

        return $booking;
    }

    private function createShowSeats(int $count): Collection
    {
        $seatIds = Seat::where('screen_id', $this->show->screen_id)
            ->orderBy('id')
            ->pluck('id');
        $showIds = [$this->show->id];
        $now = now();

        for ($index = 1; count($showIds) * $seatIds->count() < $count; $index++) {
            $scheduledAt = $now->copy()->addDays($index + 2)->setTime(18, 0);
            $showIds[] = DB::table('movie_shows')->insertGetId([
                'movie_id' => $this->show->movie_id,
                'screen_id' => $this->show->screen_id,
                'duration' => 120,
                'price' => 250,
                'scheduled_at' => $scheduledAt,
                'end_at' => $scheduledAt->copy()->addMinutes(120),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $showSeatRows = [];
        foreach (array_slice($showIds, 1) as $showId) {
            foreach ($seatIds as $seatId) {
                $showSeatRows[] = [
                    'show_id' => $showId,
                    'seat_id' => $seatId,
                    'status' => ShowSeat::STATUS_LOCKED,
                    'price' => 250,
                    'locked_until' => $now->copy()->subMinute(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('show_seats')->insert($showSeatRows);

        $showSeats = ShowSeat::whereIn('show_id', $showIds)
            ->orderBy('id')
            ->take($count)
            ->get();
        ShowSeat::whereIn('id', $showSeats->pluck('id'))
            ->update([
                'status' => ShowSeat::STATUS_LOCKED,
                'locked_until' => $now->copy()->subMinute(),
            ]);

        return $showSeats;
    }

    private function runJob(): void
    {
        app(UnlockShowSeatsJob::class)->handle(app(BookingService::class));
    }
}
