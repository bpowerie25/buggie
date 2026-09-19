<?php

namespace App\Support\Billing;

use Illuminate\Http\Request;

/**
 * Which currency a visitor is quoted in.
 *
 * Chosen by the visitor, not guessed from their address. There is no geo-IP here and
 * the site is not proxied through anything that adds a country header, so the honest
 * options were a switcher or a wrong guess. A Brit on holiday in Spain should not be
 * quoted in euro because of where the wifi is.
 */
final class Currency
{
    public const SESSION_KEY = 'buggie.currency';

    public static function all(): array
    {
        return (array) config('plans.currencies', []);
    }

    public static function default(): string
    {
        return (string) config('plans.default_currency', 'EUR');
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && array_key_exists(strtoupper($code), self::all());
    }

    /**
     * The currency for this request: an explicit choice, then a remembered one, then
     * the default.
     */
    public static function resolve(Request $request): string
    {
        $chosen = $request->query('currency');

        if (self::isSupported($chosen)) {
            $chosen = strtoupper((string) $chosen);
            $request->session()?->put(self::SESSION_KEY, $chosen);

            return $chosen;
        }

        $remembered = $request->session()?->get(self::SESSION_KEY);

        return self::isSupported($remembered) ? strtoupper($remembered) : self::default();
    }

    public static function symbol(string $code): string
    {
        return (string) (self::all()[strtoupper($code)]['symbol'] ?? '');
    }

    public static function label(string $code): string
    {
        return (string) (self::all()[strtoupper($code)]['label'] ?? strtoupper($code));
    }
}
