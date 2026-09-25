<?php

namespace App\Support\Webhooks;

/**
 * Whether a webhook URL is somewhere we are willing to POST to.
 *
 * A webhook address is supplied by a customer and fetched by *our* server, from
 * inside our network. Without a check, anyone with a workspace could point one at
 * http://169.254.169.254/ and read the cloud metadata service, or sweep our private
 * network by watching which addresses answer quickly.
 *
 * Resolved and checked here rather than trusted: `http://internal.example.com` is a
 * public-looking name that can resolve to 10.0.0.1, so the hostname alone proves
 * nothing.
 */
class SafeUrl
{
    /**
     * How hostnames are resolved, swappable for tests.
     *
     * The check does a real DNS lookup, which a test environment may not have — and
     * a test that depends on the internet fails for reasons that have nothing to do
     * with the thing being tested.
     *
     * @var null|callable(string): array<int, string>
     */
    private static $resolver = null;

    /** @param callable(string): array<int, string> $resolver */
    public static function resolveUsing(callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function resolveNormally(): void
    {
        self::$resolver = null;
    }

    /** Anything not routable on the public internet. */
    private const BLOCKED = [
        '0.0.0.0/8',        // this network
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // carrier-grade NAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local, including the cloud metadata service
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved
    ];

    /**
     * IPv6 ranges that reach IPv4 or private space by another name. PHP's filter
     * flags know the obvious ones; these are the translation and tunnelling forms a
     * gateway may quietly route to an internal IPv4 address.
     */
    private const BLOCKED6 = [
        '::ffff:0:0/96',    // IPv4-mapped
        '64:ff9b::/96',     // NAT64
        '64:ff9b:1::/48',   // local NAT64
        '2002::/16',        // 6to4
        'fc00::/7',         // unique local
        'fe80::/10',        // link-local
    ];

    /**
     * Options that make the HTTP client connect to exactly the address check() just
     * approved.
     *
     * Checking a name and then letting the client resolve it again leaves a gap: a
     * name with a short TTL can answer with a public address for the check and an
     * internal one a moment later for the request. Pinning closes it — the request
     * goes to the address that was checked, whatever DNS says by then. Call it only
     * after check() has passed.
     *
     * @return array<string, mixed>
     */
    public static function pinned(string $url): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if ($host === null || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return [];
        }

        $address = self::resolve($host)[0] ?? null;

        if ($address === null || self::isPrivate($address)) {
            // Changed between the check and now. Point it nowhere rather than let
            // the client look it up again.
            $address = '0.0.0.0';
        }

        $port = $parts['port'] ?? (strtolower($parts['scheme'] ?? 'https') === 'http' ? 80 : 443);
        $target = str_contains($address, ':') ? "[{$address}]" : $address;

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$target}"]]];
    }

    /** @return array{0: bool, 1: string|null} ok, and why not */
    public static function check(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return [false, 'That is not a URL.'];
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return [false, 'Only http and https addresses can be called.'];
        }

        $host = $parts['host'];

        // Allowed in development, where a webhook receiver is usually on the same
        // machine and the whole point is to try it.
        if (! app()->isProduction() && config('buggie.allow_private_webhooks', false)) {
            return [true, null];
        }

        $addresses = self::resolve($host);

        // Fails closed. A host that cannot be resolved might be a typo or might be
        // a name that only answers from inside a network we are not meant to reach,
        // and there is no way to tell the difference from here. It does mean the
        // application server needs working DNS, which is worth knowing when every
        // webhook is suddenly refused.
        if ($addresses === []) {
            return [false, "Could not resolve {$host}."];
        }

        foreach ($addresses as $address) {
            if (self::isPrivate($address)) {
                return [false, 'That address is inside a private network.'];
            }
        }

        return [true, null];
    }

    /** @return array<int, string> */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }

        // gethostbynamel first: it goes through the system resolver, which works in
        // places dns_get_record does not — notably containers that reach DNS through
        // the host rather than over UDP 53 themselves.
        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function isPrivate(string $address): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE and NO_RES_RANGE cover most of it, and the
        // explicit list covers what they miss — notably 169.254.0.0/16, which is the
        // one an attacker actually wants.
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        foreach (self::BLOCKED as $range) {
            if (self::inRange($address, $range)) {
                return true;
            }
        }

        foreach (self::BLOCKED6 as $range) {
            if (self::inRange6($address, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange6(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ip = @inet_pton($address);
        $net = @inet_pton($subnet);

        if ($ip === false || $net === false || strlen($ip) !== 16 || strlen($net) !== 16) {
            return false;
        }

        $bytes = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;

        if (substr($ip, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ip[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    private static function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ip = ip2long($address);
        $net = ip2long($subnet);

        // IPv6, or something unparseable: the filter check above has already had its
        // say, and guessing here would be worse than deferring to it.
        if ($ip === false || $net === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ip & $mask) === ($net & $mask);
    }
}
