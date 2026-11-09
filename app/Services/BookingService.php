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
            'show_seat_id' => $showSeat->id,
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

    /**
     * End several bookings at once and give their seats back. The ONE place that does it.
     *
     * Used by: the expiry job (EXPIRED), cancel (CANCELLED), later payment failure (FAILED).
     * Returns how many bookings were actually ended. Bookings that are no longer active
     * (already ended, or confirmed by a webhook a moment ago) are left alone.
     *
     * $skipLocked: the expiry job passes true, so a booking being handled right now by a
     * webhook is skipped instead of waited for (the next run sees it again). Cancel passes
     * false and waits, because the user needs a definite answer.
     */
    public function releaseHolds(array $bookingIds, string $endStatus, bool $skipLocked = false): int
    {
        if ($bookingIds === []) {
            return 0;
        }
 
        return DB::transaction(function () use ($bookingIds, $endStatus, $skipLocked): int {
            // Lock only the bookings that are still active. PostgreSQL applies the status
            // filter to the locked, up-to-date row, so a booking confirmed while we waited
            // drops out. orderBy('id') = same lock order everywhere, so no deadlocks.
            $query = Booking::query()
                ->whereIn('id', $bookingIds)
                ->whereIn('status', Booking::ACTIVE_STATUSES)
                ->orderBy('id');
 
            $skipLocked ? $query->lock('for update skip locked') : $query->lockForUpdate();
 
            $ids = $query->pluck('id')->all();
 
            if ($ids === []) {
                return 0;
            }
 
            // The seats these bookings hold, found through their own pending lines.
            ShowSeat::where('status', ShowSeat::STATUS_LOCKED)
                ->whereIn('id', BookingSeat::select('show_seat_id')
                    ->whereIn('booking_id', $ids)
                    ->where('status', BookingSeat::STATUS_PENDING))
                ->update([
                    'status' => ShowSeat::STATUS_AVAILABLE,
                    'locked_until' => null,
                ]);
 
            BookingSeat::whereIn('booking_id', $ids)
                ->where('status', BookingSeat::STATUS_PENDING)
                ->update(['status' => BookingSeat::STATUS_CANCELLED]);
 
            Booking::whereIn('id', $ids)->update(['status' => $endStatus]);
 
            return count($ids);
        });
    }
 
    /** One booking. Returns false (nothing changed) when it is not active any more. */
    public function releaseHold(int $bookingId, string $endStatus): bool
    {
        return $this->releaseHolds([$bookingId], $endStatus) === 1;
    }
}
