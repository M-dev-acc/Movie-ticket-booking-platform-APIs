<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingSeat extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'booking_id',
        'show_seat_id',
        'price_paid',
        'status',
    ];

    public function booking(){
        return $this->belongsTo(Booking::class);
    }

    public function seat(){
        return $this->belongsTo(ShowSeat::class);
    }
}
