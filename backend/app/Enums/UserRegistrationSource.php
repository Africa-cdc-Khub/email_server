<?php

namespace App\Enums;

enum UserRegistrationSource: string
{
    case Public = 'public';
    case System = 'system';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public registration',
            self::System => 'System user',
        };
    }
}
