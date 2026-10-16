<?php

namespace App\Modules\Licensing\Enums;

enum LicenseAdministrativeStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}
