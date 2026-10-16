<?php

namespace App\Modules\Licensing\Enums;

enum LicenseValidityStatus: string
{
    case Active = 'active';             // Regular valid window: full access
    case GracePeriod = 'grace_period'; // During grace period: read-only access allowed
    case Expired = 'expired';           // Beyond grace period: no commercial access
    case Future = 'future';             // Not started yet
    case Inactive = 'inactive';         // Administrative status is not active (suspended/draft/cancelled)
    case None = 'none';                 // No license exists
}
