<?php

namespace App\Domain\Fleet\Enums;

use App\Support\Concerns\HasLabel;

enum DriverDocumentType: string
{
    use HasLabel;

    case License = 'license';
    case NationalCard = 'national_card';
    case MedicalCertificate = 'medical_certificate';
    case Contract = 'contract';
    case BackgroundCheck = 'background_check';
    case Other = 'other';
}
