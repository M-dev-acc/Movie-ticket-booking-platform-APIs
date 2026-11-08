<?php

namespace App\Exceptions\Booking;

use RuntimeException;

class SeatUnavailableException extends RuntimeException
{
    public function __construct(string $message = "The selected seats are not available.", int $code = 409)
    {
        return parent::__construct($message, $code);
    }
}
