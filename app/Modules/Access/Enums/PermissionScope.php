<?php

namespace App\Modules\Access\Enums;

enum PermissionScope: string
{
    case Platform = 'platform';
    case Company = 'company';
}
