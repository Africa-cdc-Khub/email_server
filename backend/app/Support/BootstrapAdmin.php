<?php

namespace App\Support;

/**
 * The ADMIN_EMAIL bootstrap account from env must never be lockable —
 * blocking it would lock operators out of the only recovery path.
 */
final class BootstrapAdmin
{
    public static function email(): ?string
    {
        $email = strtolower(trim(self::envString('ADMIN_EMAIL', 'andrewa@africacdc.org')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    public static function isBootstrapEmail(?string $email): bool
    {
        $bootstrap = self::email();
        if ($bootstrap === null) {
            return false;
        }

        $candidate = strtolower(trim((string) $email));

        return $candidate !== '' && $candidate === $bootstrap;
    }

    private static function envString(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? env($key, $default);
        }

        return (string) ($value ?? $default);
    }
}
