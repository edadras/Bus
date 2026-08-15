<?php

namespace App\Domain\Support\Events;

use App\Domain\Support\Models\Complaint;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ComplaintAnswered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Complaint $complaint,
        public string $messageBody,
    ) {}
}
