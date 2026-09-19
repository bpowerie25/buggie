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
     * The plan a Stripe price belongs to, whatever currency it was sold in.
     *
     * Webhooks arrive carrying a price id and nothing else, and the same plan has one
     * per currency, so this has to look through all of them — matching only the
     * default currency would silently fail to recognise every non-euro subscriber.
     */
    public static function forPriceId(string $priceId): ?self
    {
        foreach (self::all() as $plan) {
            foreach (array_keys(Currency::all()) as $currency) {
                if ($plan->priceId($currency) === $priceId) {
                    return $plan;
                }
            }
        }

        return null;
    }

    public function name(): string
    {
        return $this->attributes['name'];
    }

    /** The display price, quoted excluding tax. */
    public function price(?string $currency = null): string
    {
        $currency ??= Currency::default();

        $prices = $this->attributes['prices'] ?? null;

        if ($prices === null) {
            // Free and self-hosted cost nothing in every currency, so there is
            // nothing to convert and no symbol worth arguing about.
            return $this->attributes['price'] === '0'
                ? Currency::symbol($currency).'0'
                : $this->attributes['price'];
        }

        // Falls back rather than fataling: a plan configured with a Stripe price but
        // no display string is a mistake, and the right response is to quote the
        // default currency, not to take the pricing page down.
        return $prices[strtoupper($currency)]['display']
            ?? $prices[Currency::default()]['display']
            ?? $this->attributes['price']
            ?? '';
    }

    public function blurb(): string
    {
        return $this->attributes['blurb'];
    }

    /** The Stripe price for this plan in a given currency, if it has one. */
    public function priceId(?string $currency = null): ?string
    {
        $currency ??= Currency::default();

        return ($this->attributes['prices'][strtoupper($currency)]['price_id'] ?? null) ?: null;
    }

    public function isSubscribable(?string $currency = null): bool
    {
        return $this->priceId($currency) !== null;
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
    /** @return array<string, mixed> */
    public function toArray(?string $currency = null): array
    {
        $currency ??= Currency::default();

        return [
            'key' => $this->key,
            'name' => $this->name(),
            'price' => $this->price($currency),
            'currency' => $currency,
            'blurb' => $this->blurb(),
            'limits' => $this->limits(),
            'subscribable' => $this->isSubscribable($currency),
        ];
    }
}
