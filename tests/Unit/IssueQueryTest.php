<?php

namespace Tests\Unit;

use App\Support\Issues\IssueQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IssueQueryTest extends TestCase
{
    #[Test]
    public function it_separates_operators_from_free_text(): void
    {
        $query = IssueQuery::parse('is:open assignee:@me checkout broken');

        $this->assertSame('open', $query->state());
        $this->assertSame('@me', $query->first('assignee'));
        $this->assertSame('checkout broken', $query->text);
    }

    #[Test]
    public function it_defaults_to_open(): void
    {
        $this->assertSame('open', IssueQuery::parse('')->state());
        $this->assertSame('open', IssueQuery::parse('checkout')->state());
        $this->assertSame('any', IssueQuery::parse('is:any')->state());
        // A nonsense value falls back rather than showing nothing.
        $this->assertSame('open', IssueQuery::parse('is:sideways')->state());
    }

    #[Test]
    public function a_leading_dash_negates(): void
    {
        $query = IssueQuery::parse('-label:wontfix label:regression');

        $this->assertSame(['regression'], $query->all('label'));
        $this->assertSame(['wontfix'], $query->all('label', negated: true));
    }

    #[Test]
    public function labels_accumulate_but_single_valued_keys_replace(): void
    {
        $query = IssueQuery::parse('label:a label:b assignee:sam assignee:jo');

        $this->assertSame(['a', 'b'], $query->all('label'));
        $this->assertSame(['jo'], $query->all('assignee'), 'Later wins for single-valued keys.');
    }

    #[Test]
    public function exclusions_accumulate_even_for_single_valued_keys(): void
    {
        // Excluding is a set operation: naming two people means neither of them.
        $query = IssueQuery::parse('-assignee:sam -assignee:jo');

        $this->assertSame(['sam', 'jo'], $query->all('assignee', negated: true));

        // The inclusive side still replaces, because an issue has one assignee.
        $this->assertSame(['jo'], IssueQuery::parse('assignee:sam assignee:jo')->all('assignee'));
    }

    #[Test]
    public function quoted_values_survive_spaces(): void
    {
        $query = IssueQuery::parse('label:"needs repro" checkout');

        $this->assertSame(['needs repro'], $query->all('label'));
        $this->assertSame('checkout', $query->text);
    }

    #[Test]
    public function unknown_operators_are_treated_as_text(): void
    {
        // Otherwise a typo silently changes what you are looking at.
        $query = IssueQuery::parse('sevrity:high broken');

        $this->assertSame([], $query->all('priority'));
        $this->assertSame('sevrity:high broken', $query->text);
    }

    #[Test]
    public function it_round_trips_through_its_canonical_form(): void
    {
        $original = IssueQuery::parse('checkout -label:wontfix is:open label:a assignee:@me');

        $this->assertSame(
            'is:open assignee:@me label:a -label:wontfix checkout',
            (string) $original,
        );

        // Reparsing the canonical form must produce the same thing.
        $this->assertSame((string) $original, (string) IssueQuery::parse((string) $original));
    }

    #[Test]
    public function chips_add_and_remove_terms(): void
    {
        $query = IssueQuery::parse('is:open');

        $withLabel = $query->with('label', 'regression');
        $this->assertSame('is:open label:regression', (string) $withLabel);

        $this->assertSame('is:open', (string) $withLabel->without('label', 'regression'));

        // Removing one of several leaves the rest.
        $two = $withLabel->with('label', 'urgent');
        $this->assertSame('is:open label:urgent', (string) $two->without('label', 'regression'));
    }

    #[Test]
    public function removing_a_key_clears_both_polarities(): void
    {
        $query = IssueQuery::parse('label:a -label:b');

        $this->assertSame('', (string) $query->without('label'));
    }

    #[Test]
    public function a_value_containing_spaces_is_requoted(): void
    {
        $query = IssueQuery::parse('')->with('label', 'needs repro');

        $this->assertSame('label:"needs repro"', (string) $query);
        $this->assertSame(['needs repro'], IssueQuery::parse((string) $query)->all('label'));
    }
}
