<?php

namespace App\Enums;

enum BuildType: string
{
    case App = 'app';
    case Theme = 'theme';
    case Sdk = 'sdk';
    case Firmware = 'firmware';
}
