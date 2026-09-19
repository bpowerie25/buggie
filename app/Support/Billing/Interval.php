<?php

namespace App\Support\Billing;

use Illuminate\Http\Request;

/**
 * Whether a visitor is being quoted a monthly or an annual price.
 *
 * Chosen the same way the currency is — by the visitor, remembered in the session —
 * so that switching to annual on the pricing page and clicking through to register
 * does not quietly revert to monthly on the way back.
 */
final class Interval
{
    public const SESSION_KEY = 'buggie.interval';

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return (array) config('plans.intervals', []);
    }

    public static function default(): string
    {
        return (string) config('plans.default_interval', 'month');
    }

    public static function isSupported(?string $interval): bool
    {
        return $interval !== null && array_key_exists(strtolower($interval), self::all());
    }

    /**
     * The interval for this request: an explicit choice, then a remembered one, then
     * the default.
     */
    public static function resolve(Request $request): string
    {
        $chosen = $request->query('interval');

        if (self::isSupported($chosen)) {
            $chosen = strtolower((string) $chosen);
            $request->session()?->put(self::SESSION_KEY, $chosen);

            return $chosen;
        }

        $remembered = $request->session()?->get(self::SESSION_KEY);

        return self::isSupported($remembered) ? strtolower($remembered) : self::default();
    }

    public static function label(string $interval): string
    {
        return (string) (self::all()[strtolower($interval)]['label'] ?? ucfirst($interval));
    }

    public static function suffix(string $interval): string
    {
        return (string) (self::all()[strtolower($interval)]['suffix'] ?? '/ '.$interval);
    }

    /** How many months one billing period covers, used to work out the annual saving. */
    public static function months(string $interval): int
    {
        return (int) (self::all()[strtolower($interval)]['months'] ?? 1);
    }
}
