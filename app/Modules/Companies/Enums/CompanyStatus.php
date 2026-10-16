<?php

namespace App\Modules\Companies\Enums;

enum CompanyStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}
