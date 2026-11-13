<?php

namespace App\Exceptions;

use RuntimeException;

class OutlookAvailabilityUnavailableException extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Calendar availability could not be verified. Please try again.', 0, $previous);
    }
}
