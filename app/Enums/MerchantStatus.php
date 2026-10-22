<?php

namespace App\Enums;

enum MerchantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
