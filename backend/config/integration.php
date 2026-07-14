<?php

return [
    'jwt_secret' => ($secret = (string) env('JWT_SECRET', '')) !== ''
        ? $secret
        : (string) env('APP_KEY', ''),

    // Token lifetime in minutes (default 24 hours).
    'jwt_ttl' => (int) env('JWT_TTL', 1440),
];
