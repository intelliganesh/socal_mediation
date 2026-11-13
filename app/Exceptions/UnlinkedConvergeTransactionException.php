<?php

namespace App\Exceptions;

use DomainException;

class UnlinkedConvergeTransactionException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Converge transaction could not be linked to this payment.');
    }
}
