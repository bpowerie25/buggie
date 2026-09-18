<?php

namespace Tests\Unit;

use App\Support\Mail\EmailBody;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EmailBodyTest extends TestCase
{
    #[Test]
    public function it_drops_the_quoted_thread(): void
    {
        $body = <<<'EMAIL'
        Still broken for us this morning.

        On Tue, 17 Sep 2026 at 09:14, Buggy <noreply@buggy.app> wrote:
        > Sam Rivera moved this from Todo to In Progress
        > Open issue: https://acme.buggy.app/issues/WEB-12
        EMAIL;

        $this->assertSame('Still broken for us this morning.', EmailBody::extract($body));
    }

    #[Test]
    public function it_drops_the_signature(): void
    {
        $body = "Any update on this?\n\n--\nAna Silva\nHead of Operations\nAcme Ltd";

        $this->assertSame('Any update on this?', EmailBody::extract($body));
    }

    #[Test]
    public function it_handles_outlook_style_quoting(): void
    {
        $body = "Confirmed fixed, thanks.\n\n-----Original Message-----\nFrom: Buggy\nSent: Tuesday";

        $this->assertSame('Confirmed fixed, thanks.', EmailBody::extract($body));
    }

    #[Test]
    public function it_keeps_multiple_paragraphs(): void
    {
        $body = "It happens on Safari.\n\nChrome is fine.\n\nOn Mon, someone wrote:\n> hello";

        $this->assertSame("It happens on Safari.\n\nChrome is fine.", EmailBody::extract($body));
    }

    #[Test]
    public function an_empty_reply_stays_empty(): void
    {
        $this->assertSame('', EmailBody::extract(null));
        $this->assertSame('', EmailBody::extract("\n\n> only quoted text\n"));
    }

    #[Test]
    public function it_reads_the_routing_token_from_the_recipient(): void
    {
        $this->assertSame(['bugs', 'abc123'], EmailBody::parseRecipient('bugs+abc123@in.buggy.app'));
        $this->assertSame(
            ['reply', 'web-12.abc123'],
            EmailBody::parseRecipient('Buggy <reply+WEB-12.abc123@in.buggy.app>'),
        );

        // Our address may not be first in a multi-recipient header.
        $this->assertSame(
            ['bugs', 'abc123'],
            EmailBody::parseRecipient('someone@else.com, bugs+abc123@in.buggy.app'),
        );

        $this->assertNull(EmailBody::parseRecipient('hello@in.buggy.app'));
        $this->assertNull(EmailBody::parseRecipient(null));
    }

    #[Test]
    public function it_separates_the_sender_name_from_the_address(): void
    {
        $this->assertSame('Ana Silva', EmailBody::senderName('Ana Silva <ana@example.com>'));
        $this->assertSame('ana@example.com', EmailBody::senderEmail('Ana Silva <ana@example.com>'));
        $this->assertSame('ana@example.com', EmailBody::senderEmail('ana@example.com'));
        $this->assertNull(EmailBody::senderName('ana@example.com'));
    }
}
