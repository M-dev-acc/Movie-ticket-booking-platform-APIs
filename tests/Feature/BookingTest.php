<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\MovieShow;
use App\Models\Seat;
use App\Models\ShowSeat;
use App\Models\Theater;
use App\Models\User;
use Database\Seeders\DemoShowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Proves the core booking flow: POST /api/v1/bookings/confirm
 *
 *   php artisan test --filter=BookingTest
 *
 * Needs: PostgreSQL in .env.testing, and the seat_id -> show_seat_id rename finished.
 *
 * Demo data comes from DemoShowSeeder: one show (price 250) on a screen with 20 seats,
 * and 20 show_seats rows, all 'available'. Seats are picked in a fixed order (A1, A2, ...).
 *
 * Not covered here (next tests): two requests truly at the same time, and the rollback
 * when a seat is taken after the early availability check.
 */
class BookingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/bookings/confirm';

    private const PRICE = 250;

    private User $user;

    private MovieShow $show;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie caches roles between tests; clear it so each test starts clean.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'user']);

        $this->user = $this->makeUser();

        // DemoShowSeeder needs a theater to put its screen in.
        Theater::factory()->create();
        $this->seed(DemoShowSeeder::class);

        $this->show = MovieShow::firstOrFail();
    }

    #[Test]
    public function booking_a_seat_reserves_the_booking_and_locks_the_seat(): void
    {
        $seats = $this->seats(1);
        $seat = $seats->first();

        $this->bookAs($this->user, $this->payload($seats))
            ->assertOk() // the endpoint returns 200 today (not 201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', Booking::STATUS_RESERVED);

        // The booking
        $booking = Booking::firstOrFail();
        $this->assertSame($this->user->id, $booking->user_id);
        $this->assertSame($this->show->id, $booking->show_id);
        $this->assertEquals(self::PRICE, $booking->total_amount, 'Total comes from show_seats.price');

        // The seat is held for about 15 minutes
        $showSeat = $this->showSeatFor($seat);
        $this->assertSame(ShowSeat::STATUS_LOCKED, $showSeat->status);
        $this->assertTrue(
            $showSeat->locked_until->between(now()->addMinutes(14), now()->addMinutes(16)),
            'The hold should last about 15 minutes.'
        );

        // The booking line points at that exact show_seats row
        $line = BookingSeat::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame($showSeat->id, (int) $line->show_seat_id);
        $this->assertSame(BookingSeat::STATUS_PENDING, $line->status);
        $this->assertEquals(self::PRICE, $line->price_paid, 'Price is saved on the line when booking');

        // Nothing else changed: the other 19 seats are still free
        $this->assertSame(
            19,
            ShowSeat::where('show_id', $this->show->id)->where('status', ShowSeat::STATUS_AVAILABLE)->count()
        );
    }

    #[Test]
    public function booking_two_seats_charges_both_and_locks_both(): void
    {
        $this->bookAs($this->user, $this->payload($this->seats(2)))->assertOk();

        $booking = Booking::firstOrFail();
        $this->assertEquals(self::PRICE * 2, $booking->total_amount);
        $this->assertSame(2, BookingSeat::where('booking_id', $booking->id)->count());
        $this->assertSame(
            2,
            ShowSeat::where('show_id', $this->show->id)->where('status', ShowSeat::STATUS_LOCKED)->count()
        );
    }

    #[Test]
    public function a_seat_that_is_already_held_cannot_be_booked_again(): void
    {
        $payload = $this->payload($this->seats(1));

        $this->bookAs($this->user, $payload)->assertOk();

        $otherUser = $this->makeUser();

        $this->bookAs($otherUser, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('seats');

        // Still exactly one booking, and it still belongs to the first user
        $this->assertSame(1, Booking::count());
        $this->assertSame(1, BookingSeat::count());
        $this->assertSame($this->user->id, Booking::firstOrFail()->user_id);
    }

    // ------------------------------------------------------------------ helpers

    private function makeUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        return $user;
    }

    /** Physical seats of the demo screen in a fixed order: A1, A2, A3 ... */
    private function seats(int $count): Collection
    {
        return Seat::where('screen_id', $this->show->screen_id)
            ->orderBy('row')
            ->orderBy('number')
            ->take($count)
            ->get();
    }

    /** The request body the API expects: the show, and the physical seat ids. */
    private function payload(Collection $seats): array
    {
        return [
            'show_id' => $this->show->id,
            'seats' => $seats->map(fn (Seat $seat) => ['id' => $seat->id])->all(),
        ];
    }

    private function showSeatFor(Seat $seat): ShowSeat
    {
        return ShowSeat::where('show_id', $this->show->id)
            ->where('seat_id', $seat->id)
            ->firstOrFail();
    }

    private function bookAs(User $user, array $payload): TestResponse
    {
        // The auth guard remembers the user from an earlier request in the same test.
        // Forget it, so a second user in one test is really the one making the request.
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web')->postJson(self::URL, $payload);
    }
}