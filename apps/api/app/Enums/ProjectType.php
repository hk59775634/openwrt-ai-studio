<?php

namespace App\Enums;

enum ProjectType: string
{
    case App = 'app';
    case Theme = 'theme';
    case Sdk = 'sdk';
    case Firmware = 'firmware';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
