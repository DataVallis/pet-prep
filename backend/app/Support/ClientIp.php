<?php

namespace App\Support;

/**
 * Rate-limit identity of a client address (M2-02).
 *
 * An IPv6 client usually controls a whole /64 (SLAAC, privacy addresses),
 * so per-address limits would be trivial to dodge: IPv6 is keyed on its /64
 * prefix ("2001:db8:1:2::/64"). IPv4 stays the full address.
 */
final class ClientIp
{
    public static function rateLimitKey(?string $ip): string
    {
        $ip = (string) $ip;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        // IPv4-mapped (::ffff:a.b.c.d) → the IPv4 address.
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
