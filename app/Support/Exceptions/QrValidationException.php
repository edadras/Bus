<?php

namespace App\Support\Exceptions;

class QrValidationException extends DomainException
{
    public function __construct(string $reason)
    {
        parent::__construct('qr_'.$reason, __('errors.qr_'.$reason), 422, ['reason' => $reason]);
    }
}
