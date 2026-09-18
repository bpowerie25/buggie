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

    public static function forPriceId(string $priceId): ?self
    {
        foreach (self::all() as $plan) {
            if ($plan->priceId() === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    public function name(): string
    {
        return $this->attributes['name'];
    }

    public function price(): string
    {
        return $this->attributes['price'];
    }

    public function blurb(): string
    {
        return $this->attributes['blurb'];
    }

    public function priceId(): ?string
    {
        return $this->attributes['price_id'] ?: null;
    }

    public function isSubscribable(): bool
    {
        return $this->priceId() !== null;
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
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name(),
            'price' => $this->price(),
            'blurb' => $this->blurb(),
            'limits' => $this->limits(),
            'subscribable' => $this->isSubscribable(),
        ];
    }
}
