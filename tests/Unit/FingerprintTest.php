<?php

namespace Tests\Unit;

use App\Support\Reports\Fingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FingerprintTest extends TestCase
{
    #[Test]
    public function the_same_bug_hit_by_different_people_collapses_to_one_fingerprint(): void
    {
        $first = Fingerprint::for([
            'message' => "Cannot read properties of null (reading 'total') for order 8412",
            'stack' => "TypeError\n  at calcTotal (https://acme.com/build/app-a1b2c3d4.js:12:44)",
        ], 'https://acme.com/orders/8412/checkout');

        $second = Fingerprint::for([
            'message' => "Cannot read properties of null (reading 'total') for order 9931",
            'stack' => "TypeError\n  at calcTotal (https://acme.com/build/app-a1b2c3d4.js:12:44)",
        ], 'https://acme.com/orders/9931/checkout');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function a_redeploy_does_not_split_the_group(): void
    {
        // Same code, new build hash — still the same bug.
        $before = Fingerprint::for([
            'message' => 'Boom',
            'stack' => 'at f (https://acme.com/build/app-aaaaaaaa.js:5:1)',
        ], 'https://acme.com/x');

        $after = Fingerprint::for([
            'message' => 'Boom',
            'stack' => 'at f (https://acme.com/build/app-bbbbbbbb.js:5:1)',
        ], 'https://acme.com/x');

        $this->assertSame($before, $after);
    }

    #[Test]
    public function different_bugs_stay_apart(): void
    {
        $checkout = Fingerprint::for(
            ['message' => 'Boom', 'stack' => 'at pay (https://acme.com/app.js:1:1)'],
            'https://acme.com/checkout',
        );

        $differentPlace = Fingerprint::for(
            ['message' => 'Boom', 'stack' => 'at pay (https://acme.com/app.js:1:1)'],
            'https://acme.com/settings',
        );

        $differentError = Fingerprint::for(
            ['message' => 'Kaboom', 'stack' => 'at pay (https://acme.com/app.js:1:1)'],
            'https://acme.com/checkout',
        );

        $this->assertNotSame($checkout, $differentPlace);
        $this->assertNotSame($checkout, $differentError);
    }

    #[Test]
    public function a_report_with_no_error_is_never_grouped(): void
    {
        // Human prose is not reliably comparable, and merging two people's different
        // problems is worse than two inbox rows.
        $this->assertNull(Fingerprint::for(null, 'https://acme.com/x'));
        $this->assertNull(Fingerprint::for(['message' => ''], 'https://acme.com/x'));
        $this->assertNull(Fingerprint::for(['message' => '   '], null));
    }

    #[Test]
    public function the_stack_frame_skips_dependencies(): void
    {
        $stack = <<<'STACK'
        TypeError: x is not a function
            at Object.get (https://acme.com/node_modules/.vite/react.js:99:1)
            at useThing (https://acme.com/node_modules/lodash/lodash.js:1:1)
            at Checkout (https://acme.com/assets/checkout.js:42:7)
        STACK;

        // The first application frame is where the bug is; the library frames are not.
        $this->assertSame('/assets/checkout.js:42:7', Fingerprint::topApplicationFrame($stack));
    }

    #[Test]
    public function routes_normalise_their_identifiers(): void
    {
        $this->assertSame('/orders/:id/items/:id', Fingerprint::routePattern('https://a.com/orders/12/items/9'));
        $this->assertSame(
            '/users/:id',
            Fingerprint::routePattern('https://a.com/users/3f2504e0-4f89-11d3-9a0c-0305e82c3301'),
        );
        $this->assertSame('/pricing', Fingerprint::routePattern('https://a.com/pricing?utm=x#top'));
        $this->assertSame('/', Fingerprint::routePattern('https://a.com/'));
    }

    #[Test]
    public function message_normalisation_removes_the_varying_parts(): void
    {
        $this->assertSame(
            'Failed to load :str after :n retries (:uuid)',
            Fingerprint::normalizeMessage(
                'Failed to load "invoice.pdf" after 3 retries (3f2504e0-4f89-11d3-9a0c-0305e82c3301)',
            ),
        );
    }
}
