<?php

namespace Tests\Feature;

use App\Support\Billing\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Monthly and annual terms.
 *
 * The risk being guarded here is not that the page looks wrong — it is that someone
 * is quoted one number and charged another, which is a chargeback rather than an
 * email.
 */
class PricingIntervalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['buggie.hosted' => true]);
    }

    #[Test]
    public function the_default_quote_is_monthly(): void
    {
        // The smaller commitment is the one to lead with.
        $this->get($this->centralUrl('/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('interval', 'month')
                ->where('plans.2.price', '€19')
                ->where('plans.2.interval_suffix', '/ month'));
    }

    #[Test]
    public function a_visitor_can_ask_for_the_annual_price(): void
    {
        $this->get($this->centralUrl('/?interval=year'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('interval', 'year')
                ->where('plans.2.price', '€190')
                ->where('plans.2.interval_suffix', '/ year'));
    }

    #[Test]
    public function the_annual_saving_is_worked_out_not_written_down(): void
    {
        // €19 × 12 = €228 against €190. Asserted as arithmetic rather than as the
        // string "€38", so changing a price cannot leave a stale saving on the page.
        $studio = Plan::find('studio');

        $this->assertSame(
            ($studio->amount('EUR', 'month') * 12) - $studio->amount('EUR', 'year'),
            $studio->saving('EUR', 'year'),
        );

        $this->assertSame('€38', $studio->toArray('EUR', 'year')['saving']);
    }

    #[Test]
    public function every_paid_plan_saves_something_on_the_annual_term(): void
    {
        // An annual price at or above twelve months' money is a reason not to buy it.
        foreach (Plan::all() as $plan) {
            if ($plan->amount('EUR', 'month') === null) {
                continue;
            }

            foreach (['EUR', 'GBP', 'USD'] as $code) {
                $this->assertNotNull(
                    $plan->saving($code, 'year'),
                    "Plan [{$plan->key}] saves nothing annually in [{$code}].",
                );
            }
        }
    }

    #[Test]
    public function the_monthly_term_advertises_no_saving(): void
    {
        // A "save €38" badge on the monthly card would be a lie.
        $this->assertNull(Plan::find('studio')->saving('EUR', 'month'));
        $this->assertNull(Plan::find('studio')->toArray('EUR', 'month')['saving']);
    }

    #[Test]
    public function the_price_says_its_period_even_before_stripe_is_configured(): void
    {
        // This is what the live site was doing: with no Stripe price ids set, every
        // paid plan rendered a bare "€19" — no period, no VAT note. The period
        // describes the price; it has nothing to do with whether checkout is wired up.
        config([
            'plans.plans.studio.prices.EUR.month.price_id' => null,
            'plans.plans.studio.prices.EUR.year.price_id' => null,
        ]);

        $studio = Plan::find('studio')->toArray('EUR', 'month');

        $this->assertFalse($studio['subscribable'], 'Nothing to buy without a Stripe price.');
        $this->assertTrue($studio['priced'], 'But it still costs €19 a month.');
        $this->assertSame('/ month', $studio['interval_suffix']);
    }

    #[Test]
    public function a_free_plan_is_never_dressed_up_with_a_period(): void
    {
        // The paired positive control for the test above: "priced" has to actually
        // distinguish something, or it is just "true" with extra steps.
        foreach (['free', 'self_hosted'] as $key) {
            $this->assertFalse(
                Plan::find($key)->toArray('EUR', 'month')['priced'],
                "Plan [{$key}] costs nothing and must not be quoted per month.",
            );
        }
    }

    #[Test]
    public function the_choice_is_remembered(): void
    {
        // Otherwise switching to annual and clicking through to register quotes the
        // monthly price on the way back.
        $this->get($this->centralUrl('/?interval=year'))->assertOk();

        $this->get($this->centralUrl('/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('interval', 'year'));
    }

    #[Test]
    public function a_term_we_do_not_sell_falls_back_rather_than_breaking(): void
    {
        $this->get($this->centralUrl('/?interval=fortnight'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('interval', 'month')
                ->where('plans.2.price', '€19'));
    }

    #[Test]
    public function the_term_and_the_currency_do_not_reset_each_other(): void
    {
        // Both are links carrying the other's value. Losing one on switching the other
        // is how a visitor ends up quoted €190 having asked for dollars.
        $this->get($this->centralUrl('/?currency=USD&interval=year'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('currency', 'USD')
                ->where('interval', 'year')
                ->where('plans.2.price', '$190'));
    }

    #[Test]
    public function each_term_has_its_own_stripe_price(): void
    {
        // Selling a year against the monthly price id charges €19 for twelve months
        // of service, and the copy-paste that causes it lives in the config file, not
        // in the environment. Asserted against the source rather than the resolved
        // values, which are empty in development — a version of this test that walked
        // priceId() skipped every row here and would have passed forever.
        $source = file_get_contents(config_path('plans.php'));

        preg_match_all("/env\('(STRIPE_PRICE_[A-Z_]+)'\)/", $source, $matches);

        $names = $matches[1];

        $expected = 2 * count(config('plans.currencies')) * count(config('plans.intervals'));

        $this->assertCount($expected, $names, 'Not every paid plan, currency and term has a Stripe price.');
        $this->assertSame($names, array_values(array_unique($names)), 'A Stripe price variable is used twice.');
    }

    #[Test]
    public function checkout_charges_the_term_that_was_asked_for(): void
    {
        config([
            'plans.plans.studio.prices.EUR.month.price_id' => 'price_month',
            'plans.plans.studio.prices.EUR.year.price_id' => 'price_year',
        ]);

        $studio = Plan::find('studio');

        $this->assertSame('price_month', $studio->priceId('EUR', 'month'));
        $this->assertSame('price_year', $studio->priceId('EUR', 'year'));
    }

    #[Test]
    public function a_term_with_no_stripe_price_cannot_be_checked_out(): void
    {
        // Half-configured is the dangerous state: monthly live, annual not yet created
        // in Stripe. The annual button has to refuse rather than fall back to the
        // monthly price and charge a month for a year.
        config([
            'buggie.hosted' => true,
            'plans.plans.studio.prices.EUR.month.price_id' => 'price_month',
            'plans.plans.studio.prices.EUR.year.price_id' => null,
        ]);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/billing/checkout'), [
                'plan' => 'studio',
                'interval' => 'year',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function the_owner_can_open_the_billing_page(): void
    {
        // Regression: the currency lookup here read a $request the method never took,
        // so every owner got a 500. Nothing covered the successful load — the only
        // tests touching this route asserted a 403 and a 404.
        config(['buggie.hosted' => true]);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/settings/billing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('interval', 'month'));
    }

    #[Test]
    public function the_billing_page_can_be_switched_to_annual(): void
    {
        config(['buggie.hosted' => true]);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/settings/billing?interval=year'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('interval', 'year')
                ->where('plans.2.price', '€190'));
    }
}
