<?php

namespace Tests\Unit;

use App\Support\Chat\ChatNotice;
use App\Support\Chat\SlackMessage;
use App\Support\Chat\TeamsMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two message formats, which have nothing in common but JSON.
 *
 * No database and no HTTP: these are about the shape of the body, which is the part
 * a provider silently refuses to render when it is wrong.
 */
class ChatMessageTest extends TestCase
{
    private function notice(string $title = 'WEB-42 · Checkout fails'): ChatNotice
    {
        return new ChatNotice(
            heading: 'New issue',
            title: $title,
            url: 'https://acme.buggie.eu/issues/WEB-42',
            fields: ['Project' => 'Acme Web', 'Status' => 'Todo', 'Assignee' => null],
        );
    }

    // ------------------------------------------------------------------- slack

    #[Test]
    public function slack_gets_blocks_and_a_fallback_line(): void
    {
        $body = (new SlackMessage)->body($this->notice(), 'https://hooks.slack.com/services/x');

        // Blocks alone arrive as a blank line in the notification preview and in
        // the channel list, so `text` is sent as well.
        $this->assertArrayHasKey('text', $body);
        $this->assertNotSame('', $body['text']);

        $this->assertSame('section', $body['blocks'][0]['type']);
        $this->assertStringContainsString(
            '<https://acme.buggie.eu/issues/WEB-42|WEB-42 · Checkout fails>',
            $body['blocks'][0]['text']['text'],
        );
    }

    #[Test]
    public function slack_drops_a_field_with_no_value(): void
    {
        $fields = (new SlackMessage)
            ->body($this->notice(), 'https://hooks.slack.com/services/x')['blocks'][1]['fields'];

        // Assignee was null. "Assignee: —" costs a line in a channel and says
        // nothing.
        $this->assertCount(2, $fields);
        $this->assertStringContainsString('Project', $fields[0]['text']);
    }

    #[Test]
    public function slack_escapes_the_three_characters_that_break_its_markup(): void
    {
        $body = (new SlackMessage)->body(
            $this->notice('Cannot read <script> & "quotes" > here'),
            'https://hooks.slack.com/services/x',
        );

        $text = $body['blocks'][0]['text']['text'];

        $this->assertStringContainsString('&lt;script&gt;', $text);
        $this->assertStringContainsString('&amp;', $text);

        // And only those three: Slack asks that nothing else be escaped, and a
        // general HTML escaper would put `&quot;` in the channel.
        $this->assertStringContainsString('"quotes"', $text);
    }

    // ------------------------------------------------------------------- teams

    #[Test]
    public function a_connector_address_gets_a_message_card(): void
    {
        $body = (new TeamsMessage)->body(
            $this->notice(),
            'https://acme.webhook.office.com/webhookb2/abc/IncomingWebhook/def',
        );

        $this->assertSame('MessageCard', $body['@type']);
        // A card with no summary is rejected outright rather than rendered plainly.
        $this->assertNotSame('', $body['summary']);
        $this->assertFalse($body['sections'][0]['markdown']);
        $this->assertSame('Open in Buggie', $body['potentialAction'][0]['name']);
        $this->assertSame(
            [['name' => 'Project', 'value' => 'Acme Web'], ['name' => 'Status', 'value' => 'Todo']],
            $body['sections'][0]['facts'],
        );
    }

    #[Test]
    public function a_workflow_address_gets_an_adaptive_card_in_its_envelope(): void
    {
        $body = (new TeamsMessage)->body(
            $this->notice(),
            'https://prod-05.westeurope.logic.azure.com:443/workflows/abc/triggers/manual/paths/invoke',
        );

        // The card on its own is accepted with a 202 and then never appears in the
        // channel, which is a miserable thing to debug.
        $this->assertSame('message', $body['type']);
        $this->assertSame(
            'application/vnd.microsoft.card.adaptive',
            $body['attachments'][0]['contentType'],
        );

        $card = $body['attachments'][0]['content'];

        $this->assertSame('AdaptiveCard', $card['type']);
        $this->assertSame('FactSet', $card['body'][2]['type']);
        $this->assertSame('Action.OpenUrl', $card['actions'][0]['type']);
    }

    #[Test]
    public function the_two_teams_formats_are_not_interchangeable(): void
    {
        // The control for the pair above: if the host made no difference, both of
        // those tests would be asserting the same thing twice.
        $teams = new TeamsMessage;
        $notice = $this->notice();

        $this->assertNotSame(
            $teams->body($notice, 'https://acme.webhook.office.com/webhookb2/abc'),
            $teams->body($notice, 'https://prod-05.westeurope.logic.azure.com/workflows/abc'),
        );
    }
}
