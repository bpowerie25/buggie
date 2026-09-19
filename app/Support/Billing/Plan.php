<?php

namespace App\Support\Billing;

use InvalidArgumentException;

/**
 * One row of config/plans.php, as an object rather than an array reach.
 */
final class Plan
{
    /** @param array<string, mixed> $attributes */
    private function __construct(
        public readonly string $key,
        private readonly array $attributes,
    ) {}

    public static function find(?string $key): self
    {
        $key ??= config('plans.default');
        $attributes = config("plans.plans.{$key}");

        if ($attributes === null) {
            throw new InvalidArgumentException("Unknown plan [{$key}].");
        }

        return new self($key, $attributes);
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return array_map(
            fn (string $key) => self::find($key),
            array_keys((array) config('plans.plans')),
        );
    }

    /**
     * The plan a Stripe price belongs to, whatever currency or term it was sold in.
     *
     * Webhooks arrive carrying a price id and nothing else, and the same plan has one
     * per currency per interval, so this has to look through all of them — matching
     * only the default would silently fail to recognise every non-euro subscriber and
     * everyone who bought a year.
     */
    public static function forPriceId(string $priceId): ?self
    {
        foreach (self::all() as $plan) {
            foreach (array_keys(Currency::all()) as $currency) {
                foreach (array_keys(Interval::all()) as $interval) {
                    if ($plan->priceId($currency, $interval) === $priceId) {
                        return $plan;
                    }
                }
            }
        }

        return null;
    }

    public function name(): string
    {
        return $this->attributes['name'];
    }

    /**
     * The raw number charged per period, excluding tax. Null for plans with no price.
     *
     * Falls back to the default currency rather than fataling: a plan configured with
     * a Stripe price but no amount is a mistake, and the right response is to quote
     * the default, not to take the pricing page down.
     */
    public function amount(?string $currency = null, ?string $interval = null): ?int
    {
        $prices = $this->attributes['prices'] ?? null;

        if ($prices === null) {
            return null;
        }

        $currency = strtoupper($currency ?? Currency::default());
        $interval = strtolower($interval ?? Interval::default());

        $amount = $prices[$currency][$interval]['amount']
            ?? $prices[Currency::default()][$interval]['amount']
            ?? null;

        return $amount === null ? null : (int) $amount;
    }

    /** The display price, quoted excluding tax. */
    public function price(?string $currency = null, ?string $interval = null): string
    {
        $currency = strtoupper($currency ?? Currency::default());

        $amount = $this->amount($currency, $interval);

        if ($amount === null) {
            // Free and self-hosted cost nothing in every currency, so there is
            // nothing to convert and no symbol worth arguing about.
            return ($this->attributes['price'] ?? '') === '0'
                ? Currency::symbol($currency).'0'
                : (string) ($this->attributes['price'] ?? '');
        }

        return Currency::symbol($currency).number_format($amount);
    }

    /**
     * What buying this term saves against paying monthly for the same span, or null
     * when there is nothing to save — the monthly term itself, or an unpriced plan.
     */
    public function saving(?string $currency = null, ?string $interval = null): ?int
    {
        $interval = strtolower($interval ?? Interval::default());
        $months = Interval::months($interval);

        if ($months <= 1) {
            return null;
        }

        $monthly = $this->amount($currency, 'month');
        $term = $this->amount($currency, $interval);

        if ($monthly === null || $term === null) {
            return null;
        }

        $saving = ($monthly * $months) - $term;

        return $saving > 0 ? $saving : null;
    }

    public function blurb(): string
    {
        return $this->attributes['blurb'];
    }

    /** The Stripe price for this plan in a given currency and term, if it has one. */
    public function priceId(?string $currency = null, ?string $interval = null): ?string
    {
        $currency = strtoupper($currency ?? Currency::default());
        $interval = strtolower($interval ?? Interval::default());

        return ($this->attributes['prices'][$currency][$interval]['price_id'] ?? null) ?: null;
    }

    public function isSubscribable(?string $currency = null, ?string $interval = null): bool
    {
        return $this->priceId($currency, $interval) !== null;
    }

    /**
     * Whether this plan costs money, which is a different question from whether it can
     * be bought right now.
     *
     * The two were conflated, and the pricing page hung "/ month" and "excluding VAT"
     * off subscribability — so with the Stripe prices not yet created, every visitor
     * saw a bare "€19" with no period and no tax note at all.
     */
    public function isPriced(?string $currency = null, ?string $interval = null): bool
    {
        return $this->amount($currency, $interval) !== null;
    }

    /** null means no limit. */
    public function limit(string $name): ?int
    {
        return $this->attributes['limits'][$name] ?? null;
    }

    /** @return array<string, int|null> */
    public function limits(): array
    {
        return $this->attributes['limits'];
    }

    /** @return array<string, mixed> */
    public function toArray(?string $currency = null, ?string $interval = null): array
    {
        $currency = strtoupper($currency ?? Currency::default());
        $interval = strtolower($interval ?? Interval::default());

        $saving = $this->saving($currency, $interval);

        return [
            'key' => $this->key,
            'name' => $this->name(),
            'price' => $this->price($currency, $interval),
            'currency' => $currency,
            'interval' => $interval,
            'interval_suffix' => Interval::suffix($interval),
            // Pre-formatted, because the front end has no currency symbol table and
            // should not grow one.
            'saving' => $saving === null
                ? null
                : Currency::symbol($currency).number_format($saving),
            'blurb' => $this->blurb(),
            'limits' => $this->limits(),
            'priced' => $this->isPriced($currency, $interval),
            'subscribable' => $this->isSubscribable($currency, $interval),
        ];
    }
}
