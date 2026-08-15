<?php

namespace App\Support\Exceptions;

class InsufficientFundsException extends DomainException
{
    public function __construct(int $required, int $available)
    {
        parent::__construct('insufficient_funds', __('errors.insufficient_funds'), 422, [
            'required' => $required,
            'available' => $available,
            'shortfall' => max(0, $required - $available),
        ]);
    }
}
