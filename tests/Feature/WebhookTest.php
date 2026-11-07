<?php

namespace Tests\Feature;

use App\Jobs\ShowSeat\PopulateShowSeatsJob;
use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\MovieShow;
use App\Models\Payment;
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

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const BOOKING_URL = '/api/v1/bookings/confirm';

    private const WEBHOOK_URL = '/api/webhooks/razorpay';

    private const PRICE = 250;

    private const ORDER_ID = 'order_test';   // our payments.gateway_order_id

    private const PAYMENT_ID = 'pay_1';      // Razorpay's payment id (what the webhook sends)

    private const SECRET = 'test_secret';

    private User $user;

    private MovieShow $show;

    protected function setUp(): void
    {
        parent::setUp();

        // The app signs and verifies with this config value, so the test sets it explicitly.
        config(['razorpay.webhook.secret' => self::SECRET]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'user']);

        $this->user = $this->makeUser();

        Theater::factory()->create();
        $this->seed(DemoShowSeeder::class);

        $this->show = MovieShow::firstOrFail();
    }

    // ------------------------------------------------------------------- tests

    #[Test]
    public function a_valid_captured_webhook_confirms_the_booking(): void
    {
        // ARRANGE: a booking waiting for payment
        $payment = $this->makePendingPayment();

        // ACT: Razorpay tells us the payment was captured
        $this->sendWebhook('payment.captured')->assertNoContent();

        // ASSERT: reload from the database. Our variables still hold the OLD values.
        $payment = $payment->fresh();
        $booking = $payment->booking;

        $this->assertSame(Payment::STATUS_CAPTURED, $payment->status);
        $this->assertSame(self::PAYMENT_ID, $payment->gateway_payment_id);
        $this->assertNotNull($payment->paid_at);

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);
        $this->assertNotNull($booking->confirmed_at);

        $showSeat = $this->showSeatOf($booking);
        $this->assertSame(ShowSeat::STATUS_BOOKED, $showSeat->status);
        $this->assertNull($showSeat->locked_until);

        $this->assertSame(BookingSeat::STATUS_CONFIRMED, $this->lineOf($booking)->status);
    }

    #[Test]
    public function a_webhook_with_a_wrong_signature_is_rejected_and_changes_nothing(): void
    {
        $payment = $this->makePendingPayment();

        $this->sendWebhook('payment.captured', 'wrong')->assertStatus(400);

        $this->assertStillWaitingForPayment($payment);
    }

    /**
     * Why a second show? With only one show, show_seats ids (1..20) happen to equal the
     * physical seat ids (1..20), so a query that mixes them up still "works" by coincidence.
     * On a second show the show_seats ids start at 21, and the mix-up shows itself.
     */
    #[Test]
    public function confirming_a_booking_on_a_second_show_books_the_right_seat(): void
    {
        $secondShow = $this->makeSecondShow();
        $payment = $this->makePendingPayment($secondShow);

        $this->sendWebhook('payment.captured')->assertNoContent();

        $booking = $payment->fresh()->booking;
        $showSeat = $this->showSeatOf($booking);

        $this->assertSame($secondShow->id, $showSeat->show_id);
        $this->assertGreaterThan(20, $showSeat->id, 'Setup check: ids must differ from seat ids.');
        $this->assertSame(ShowSeat::STATUS_BOOKED, $showSeat->status);

        // The same physical seat on the FIRST show must be untouched.
        $firstShowSeat = ShowSeat::where('show_id', $this->show->id)
            ->where('seat_id', $showSeat->seat_id)
            ->firstOrFail();
        $this->assertSame(ShowSeat::STATUS_AVAILABLE, $firstShowSeat->status);
    }

    // ----------------------------------------------------- arrange helpers

    private function makeUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        return $user;
    }

    /** Physical seats of the demo screen in a fixed order: A1, A2, ... */
    private function seats(int $count): Collection
    {
        return Seat::where('screen_id', $this->show->screen_id)
            ->orderBy('row')
            ->orderBy('number')
            ->take($count)
            ->get();
    }

    /** A second show on the same screen, with its own show_seats rows. */
    private function makeSecondShow(): MovieShow
    {
        $show = MovieShow::create([
            'movie_id' => $this->show->movie_id,
            'screen_id' => $this->show->screen_id,
            'scheduled_at' => now()->addDays(2)->setTime(18, 0),
            'duration' => 120,
            'price' => self::PRICE,
        ]);

        // Safe to run twice: the job ignores rows that already exist.
        PopulateShowSeatsJob::dispatchSync($show);

        return $show;
    }

    /**
     * Book one seat through the real endpoint, then put the booking where it is when
     * the webhook arrives: 'payment_pending', with a 'initiated' payment row.
     */
    private function makePendingPayment(?MovieShow $show = null): Payment
    {
        $show ??= $this->show;
        $seats = $this->seats(1);

        $this->bookAs($this->user, [
            'show_id' => $show->id,
            'seats' => $seats->map(fn (Seat $seat) => ['id' => $seat->id])->all(),
        ])->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_RESERVED);

        $booking = Booking::firstOrFail();
        $booking->update(['status' => Booking::STATUS_PAYMENT_PENDING]);

        return Payment::create([
            'booking_id' => $booking->id,
            'gateway_order_id' => self::ORDER_ID,
            'amount' => self::PRICE,
            'status' => Payment::STATUS_INITIATED,
        ]);
    }

    private function bookAs(User $user, array $payload): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web')->postJson(self::BOOKING_URL, $payload);
    }

    // ----------------------------------------------------------- act helper

    /**
     * Send a webhook the way Razorpay does: a raw JSON body, signed with the secret.
     * The signature is computed over the EXACT string we send ($raw), never over the array.
     * Pass $signature to send a wrong one.
     */
    private function sendWebhook(
        string $event,
        ?string $signature = null,
        string $orderId = self::ORDER_ID,
    ): TestResponse {
        $raw = json_encode([
            'event' => $event,
            'payload' => ['payment' => ['entity' => [
                'id' => self::PAYMENT_ID,
                'order_id' => $orderId,
            ]]],
        ]);

        $signature ??= hash_hmac('sha256', $raw, config('razorpay.webhook.secret'));

        return $this->call('POST', self::WEBHOOK_URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        ], $raw);
    }

    // -------------------------------------------------------- assert helpers

    private function lineOf(Booking $booking): BookingSeat
    {
        return $booking->bookingSeats()->firstOrFail();
    }

    /** The show_seats row the booking line points at. */
    private function showSeatOf(Booking $booking): ShowSeat
    {
        return ShowSeat::findOrFail($this->lineOf($booking)->show_seat_id);
    }

    /** Everything is exactly as it was before the webhook. */
    private function assertStillWaitingForPayment(Payment $payment): void
    {
        $payment = $payment->fresh();
        $booking = $payment->booking;

        $this->assertSame(Payment::STATUS_INITIATED, $payment->status);
        $this->assertNull($payment->gateway_payment_id);
        $this->assertNull($payment->paid_at);

        $this->assertSame(Booking::STATUS_PAYMENT_PENDING, $booking->status);
        $this->assertNull($booking->confirmed_at);

        $this->assertSame(ShowSeat::STATUS_LOCKED, $this->showSeatOf($booking)->status);
        $this->assertSame(BookingSeat::STATUS_PENDING, $this->lineOf($booking)->status);
    }
}