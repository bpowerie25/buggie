<?php

namespace Tests\Feature;

use App\Support\Billing\Currency;
use App\Support\Billing\Interval;
use App\Support\Billing\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PricingCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['buggie.hosted' => true]);
    }

    #[Test]
    public function the_default_quote_is_in_euro(): void
    {
        // The company is Irish, so euro is the currency the books are kept in.
        $this->get($this->centralUrl('/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('currency', 'EUR'));
    }

    #[Test]
    public function a_visitor_can_ask_for_another_currency(): void
    {
        $this->get($this->centralUrl('/?currency=GBP'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('currency', 'GBP')
                ->where('plans.2.price', '£16'));
    }

    #[Test]
    public function the_choice_is_remembered(): void
    {
        // Otherwise a visitor who switches to dollars and clicks through to register
        // is quoted in euro on the way back.
        $this->get($this->centralUrl('/?currency=USD'))->assertOk();

        $this->get($this->centralUrl('/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('currency', 'USD'));
    }

    #[Test]
    public function a_currency_we_do_not_sell_in_falls_back_rather_than_breaking(): void
    {
        $this->get($this->centralUrl('/?currency=JPY'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('currency', 'EUR'));
    }

    #[Test]
    public function every_paid_plan_is_priced_in_every_currency_and_term(): void
    {
        // A paid plan missing a price in one currency or term means a visitor sees a
        // button that cannot work. Checked against what is actually configured rather
        // than by forcing prices onto the free tiers, which have none by design.
        foreach (Plan::all() as $plan) {
            $configured = config("plans.plans.{$plan->key}.prices");

            if ($configured === null) {
                continue;
            }

            foreach (array_keys(Currency::all()) as $code) {
                $this->assertArrayHasKey(
                    $code,
                    $configured,
                    "Plan [{$plan->key}] has no price in [{$code}].",
                );

                foreach (array_keys(Interval::all()) as $term) {
                    $this->assertArrayHasKey(
                        $term,
                        $configured[$code],
                        "Plan [{$plan->key}] has no [{$term}] price in [{$code}].",
                    );

                    $this->assertGreaterThan(
                        0,
                        (int) ($configured[$code][$term]['amount'] ?? 0),
                        "Plan [{$plan->key}] has no [{$term}] amount in [{$code}].",
                    );
                }
            }
        }
    }

    #[Test]
    public function a_stripe_price_is_recognised_whatever_currency_or_term_it_was_sold_in(): void
    {
        // Webhooks arrive with a price id and nothing else. Matching only the default
        // currency and term would silently fail to recognise every non-euro subscriber
        // and everyone who bought a year — they would pay and get nothing.
        config([
            'plans.plans.studio.prices.EUR.month.price_id' => 'price_eur',
            'plans.plans.studio.prices.GBP.month.price_id' => 'price_gbp',
            'plans.plans.studio.prices.USD.month.price_id' => 'price_usd',
            'plans.plans.studio.prices.EUR.year.price_id' => 'price_eur_year',
            'plans.plans.studio.prices.GBP.year.price_id' => 'price_gbp_year',
            'plans.plans.studio.prices.USD.year.price_id' => 'price_usd_year',
        ]);

        $ids = [
            'price_eur', 'price_gbp', 'price_usd',
            'price_eur_year', 'price_gbp_year', 'price_usd_year',
        ];

        foreach ($ids as $priceId) {
            $this->assertSame('studio', Plan::forPriceId($priceId)?->key);
        }
    }

    #[Test]
    public function the_page_says_prices_exclude_vat(): void
    {
        $this->get($this->centralUrl('/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pricesExcludeTax', true));
    }

    #[Test]
    public function free_costs_nothing_in_every_currency(): void
    {
        foreach (['EUR', 'GBP', 'USD'] as $code) {
            $this->assertStringEndsWith('0', Plan::find('free')->price($code));
        }
    }
}
