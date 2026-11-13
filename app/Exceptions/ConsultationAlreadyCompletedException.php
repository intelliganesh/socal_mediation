<?php

namespace App\Exceptions;

use DomainException;

class ConsultationAlreadyCompletedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This consultation has already been completed.');
    }
}
