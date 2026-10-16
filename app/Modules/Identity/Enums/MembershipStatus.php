<?php

namespace App\Modules\Identity\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Invited = 'invited';
    case Revoked = 'revoked';
}
