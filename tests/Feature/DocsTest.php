<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocsTest extends TestCase
{
    #[Test]
    public function the_index_renders(): void
    {
        $this->get($this->centralUrl('/docs'))
            ->assertOk()
            ->assertSee('Buggie', escape: false);
    }

    #[Test]
    public function every_page_that_ships_can_be_reached(): void
    {
        // Rendered from files on disk, so a page added to docs/help without a route
        // change should just work — and one that throws should fail here rather than
        // 500 for a reader.
        foreach (glob(base_path('docs/help/*.md')) ?: [] as $file) {
            $slug = basename($file, '.md');

            $this->get($this->centralUrl("/docs/{$slug}"))
                ->assertOk();
        }
    }

    #[Test]
    public function links_between_pages_are_rewritten_to_urls(): void
    {
        // The Markdown links to widget.md so it reads correctly on disk and on
        // GitHub. On the site those have to become real URLs or every link 404s.
        $html = $this->get($this->centralUrl('/docs'))->assertOk()->getContent();

        $this->assertStringContainsString('href="/docs/triage"', $html);
        $this->assertStringNotContainsString('href="triage.md"', $html);

        // Any .md link that survives must be an absolute one out to the repository;
        // a relative one would be a dead link on the site.
        preg_match_all('~href="([^"]*\.md[^"]*)"~', $html, $matches);

        foreach ($matches[1] as $href) {
            $this->assertStringStartsWith('https://github.com/', $href);
        }
    }

    #[Test]
    public function links_out_of_the_docs_directory_go_to_the_repository(): void
    {
        // ../DESIGN.md and ../../CONTRIBUTING.md are repository files this site does
        // not serve. Resolved rather than guessed, so both depths land correctly.
        $html = $this->get($this->centralUrl('/docs'))->assertOk()->getContent();

        if (str_contains($html, 'DESIGN.md')) {
            $this->assertStringContainsString(
                'https://github.com/bpowerie25/buggie/blob/main/docs/DESIGN.md',
                $html,
            );
        }

        $this->assertStringNotContainsString('blob/main/../', $html);
    }

    #[Test]
    public function a_missing_page_is_a_404_not_an_error(): void
    {
        $this->get($this->centralUrl('/docs/no-such-page'))->assertNotFound();
    }

    #[Test]
    public function the_page_name_cannot_escape_the_docs_directory(): void
    {
        // The slug picks the file to read, so traversal is the obvious attack.
        foreach (['../.env', '..%2F.env', 'a/../../.env', 'AGENTS'] as $attempt) {
            $this->get($this->centralUrl('/docs/'.$attempt))->assertNotFound();
        }
    }

    #[Test]
    public function raw_html_in_markdown_is_escaped(): void
    {
        // Nothing in docs/help contains HTML today, but the renderer is configured to
        // escape rather than pass through, and that should stay true.
        $this->get($this->centralUrl('/docs'))
            ->assertDontSee('<script>alert', escape: false);
    }
}
