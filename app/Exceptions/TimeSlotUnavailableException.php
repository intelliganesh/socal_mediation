<?php

namespace App\Exceptions;

use DomainException;

class TimeSlotUnavailableException extends DomainException
{
    public function __construct(
        public readonly ?array $overlap = null,
        public readonly bool $liveOutlookConflict = false,
    ) {
        parent::__construct('This time slot is no longer available. Please choose another slot.');
    }
}
