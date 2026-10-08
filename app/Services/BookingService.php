<?php

namespace App\Services;

use App\Exceptions\Booking\SeatUnavailableException;
use App\Models\{
    Booking,
    BookingSeat,
    MovieShow,
    ShowSeat,
};
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function createBooking(array $inputs): Booking
    {
        DB::beginTransaction();
        try {
            $showSeats = $this->getShowSeatsById(
                $inputs['show_id'],
                data_get($inputs, 'seats.*.id'));

            $data = $this->formatBookingData($inputs['show_id'], $showSeats);
            $booking = Booking::create($data);

            $this->lockRequestedSeats($showSeats);
            $this->bookRequestedSeats($showSeats, $booking);
        } catch (\Exception $error) {
            DB::rollBack();
            throw $error;
        }
        DB::commit();

        return $booking;
    }

    private function lockRequestedSeats(EloquentCollection $showSeats): void
    {
        ShowSeat::whereIn('id', $showSeats->pluck('id')->toArray())
            ->update([
                'status' => 'locked',
                'locked_until' => now()->addMinutes(15),
            ]);
    }

    private function bookRequestedSeats(EloquentCollection $showSeats, Booking $booking): void
    {
        $currentTimestamp = now();
        $bookingSeatsData = $showSeats->map(fn($showSeat) => [
            'booking_id' => $booking->id,
            'seat_id' => $showSeat->show_seat_id,
            'price_paid' => $showSeat->price,
            'status' => BookingSeat::STATUS_PENDING,
            'created_at' => $currentTimestamp,
            'updated_at' => $currentTimestamp,
        ])->toArray();

        BookingSeat::insert($bookingSeatsData);

        $booking->update([
            'status' => Booking::STATUS_RESERVED
        ]);
    }

    public function getShowSeatsById(int $showId, array $seatIds): EloquentCollection
    {
        $showSeats = ShowSeat::select(['id', 'price', 'seat_id'])
            ->available()
            ->whereIn('seat_id', $seatIds)
            ->where('show_id', $showId)
            ->lockForUpdate()
            ->get();

        if ($showSeats->count() !== count($seatIds)) {
            throw new SeatUnavailableException("The selected seat(s) are not found.");
        }

        return $showSeats;
    }

    public function areSeatsAvailable(int $showId, array $seatIds): bool
    {
        return ShowSeat::whereIn('seat_id', $seatIds)
            ->where('show_id', $showId)
            ->where('status', 'available')
            ->count() === count($seatIds);
    }

    private function formatBookingData(int $showId, EloquentCollection $seats): array
    {
        $show = MovieShow::with('movie')
            ->findOrFail($showId);

        $totalAmount = $this->calculateTotalAmount($seats);

        return [
            'user_id' => auth()->id(),
            'show_id' => $show->id,
            'movie_id' => $show->movie->id,
            'total_amount' => $totalAmount,
        ];
    }

    private function calculateTotalAmount(EloquentCollection $seats): float
    {
        $prices = $seats->pluck('price')->toArray();
        $total = array_sum($prices);

        return $total;
    }
}
