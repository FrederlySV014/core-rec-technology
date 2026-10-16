<?php

namespace App\Modules\Provisioning\Enums;

enum ProvisioningStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Ready = 'ready';
    case Failed = 'failed';
}
