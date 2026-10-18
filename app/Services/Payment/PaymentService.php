<?php

namespace App\Services\Payment;

use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\Payment;
use App\Models\ShowSeat;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentService
{
    public function __construct(
        private PaymentGatewayInterface $gateway
    ) {
    }

    /**
     * Initiate a payment: create a Razorpay order and record it.
     * Called when the user is ready to pay for a reserved booking.
     */
    public function initiate(Booking $booking): array
    {
        if ($booking->status !== Booking::STATUS_RESERVED)
        {
            throw new RuntimeException(
                "Booking {$booking->code} is not a playable state."
            );
        }

        $order = $this->gateway->createOrder($booking);

        Payment::create([
            'booking_id' => $booking->id,
            'gateway' => 'razorpay',
            'gateway_order_id' => $order->id,
            'amount' => $booking->total_amount,
            'currency' => 'INR',
            'status' => Payment::STATUS_INITIATED,
        ]);

        $booking->update(['status' => Booking::STATUS_PAYMENT_PENDING]);

        return [
            'gateway_order_id' => $order->id,
            'amount' => $order->amount,
            'currency' => $order->currency,
            'key_id' => config('razorpay.key_id'),
            'booking_code' => $booking->code,
        ];
    }

    /**
     * Confirm a booking after the webhook arrives.
     *
     * This is called from WebhookController after signature verification.
     * It is idempotent — if called twice for the same payment, the
     * second call is a no-op. This handles Razorpay's at-least-once
     * webhook delivery guarantee.
     *
     * Docs: https://razorpay.com/docs/webhooks/
     */
    public function confirmFromWebhook(array $payload): void
    {
        $paymentEntity = $payload['payload']['payment']['entity'] ?? null;
        if(!$paymentEntity){
            return;
        }

        $gatewayOrderId = $paymentEntity['order_id'];
        $gatewayPaymentId = $paymentEntity['id'];

        $payment = Payment::where('gateway_order_id', $gatewayOrderId)->first();
        if(!$payment){
            Log::warning('Webhook recieved from unknown order.', [
                'gateway_order_id' => $gatewayOrderId,
            ]);

            return;
        }

        $booking = $payment->booking;

        DB::transaction(function () use ($payment, $booking, $paymentEntity, $gatewayPaymentId) {
            $payment->update([
                'gateway_payment_id' => $gatewayPaymentId,
                'status' => Payment::STATUS_CAPTURED,
                'gateway_response' => $paymentEntity,
                'paid_at' => now(),
            ]);

            $seatIds = $booking->bookingSeats()->pluck('show_seat_id');
            ShowSeat::where('show_id', $booking->show_id)
                ->whereIn('seat_id', $seatIds)
                ->update([
                    'status' => ShowSeat::STATUS_BOOKED,
                    'locked_until' => null,
                ]);

            $booking->bookingSeats()->update([
                'status' => BookingSeat::STATUS_CONFIRMED
            ]);

            $booking->update([
                'status' => Booking::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ]);
        });
    }

    /**
     * Handle a failed payment webhook.
     * Releases seats back to available.
     */
    public function handleFailure(array $payload): void
    {
        $paymentEntity = $payload['payload']['payment']['entity'] ?? null;
        $gatewayOrderId = $paymentEntity['order_id'] ?? null;
        if(!$gatewayOrderId){
            return;
        }

        $payment = Payment::where('gateway_order_id', $gatewayOrderId)->first();
        if(!$payment || $payment->status !== Payment::STATUS_INITIATED){
            return;
        }

        $booking = $payment->booking;
        $seatIds = $booking->bookingSeats()->pluck('show_seat_id');

        DB::transaction(function () use ($payment, $booking, $seatIds, $paymentEntity) {
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'gateway_response' => $paymentEntity,
            ]);

            ShowSeat::where('show_id', $booking->show_id)
                ->whereIn('seat_id', $seatIds)
                ->update([
                    'status' => ShowSeat::STATUS_AVAILABLE,
                    'locked_until' => null,
                ]);

            $booking->bookingSeats()->update([
                'status' => BookingSeat::STATUS_CANCELLED,
            ]);
            $booking->update([
                'status' => Booking::STATUS_CANCELLED,
            ]);

        });
    }
}
