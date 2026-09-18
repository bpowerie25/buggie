<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\View\View;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves docs/help as a documentation site.
 *
 * Rendered from the same Markdown that ships in the repository rather than copied
 * into a CMS: a self-hoster reading it on disk and a visitor reading it here are
 * looking at one file, so they cannot disagree.
 */
class DocsController extends Controller
{
    private const ROOT = 'docs/help';

    private const REPOSITORY = 'https://github.com/bpowerie25/buggie';

    public function __invoke(string $page = 'index'): View|Response
    {
        // The page name comes off the URL, so it decides which file is read. Anything
        // but plain lowercase words is refused outright rather than cleaned up:
        // rejecting is auditable, sanitising is a thing you hope you got right.
        if (! preg_match('/^[a-z0-9-]+$/', $page)) {
            abort(404);
        }

        $path = base_path(self::ROOT."/{$page}.md");

        abort_unless(is_file($path), 404);

        $markdown = (string) file_get_contents($path);

        return view('docs', [
            'title' => $this->titleOf($markdown, $page),
            'html' => $this->render($markdown),
            'pages' => $this->contents(),
            'current' => $page,
        ]);
    }

    private function render(string $markdown): string
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        $html = (string) (new MarkdownConverter($environment))->convert($markdown);

        // Links between pages are written as ./widget.md so they work when read on
        // disk, in an editor, and on GitHub. Rewritten here rather than changed in
        // the source, because the file being readable everywhere is the point.
        // ~ as the delimiter, not #: the pattern has to match an anchor fragment, and
        // a # inside a #-delimited pattern ends it early.
        $html = (string) preg_replace(
            '~href="\.?/?([a-z0-9-]+)\.md(#[^"]*)?"~',
            'href="/docs/$1$2"',
            $html,
        );

        // Links out of docs/help point at repository files — DESIGN.md, CONTRIBUTING.md
        // — which this site does not serve. They go to GitHub rather than nowhere, and
        // the path is resolved rather than guessed so ../ and ../../ both land right.
        return (string) preg_replace_callback(
            '~href="((?:\.\./)+)([A-Za-z0-9_/-]+\.md)(#[^"]*)?"~',
            function (array $m): string {
                $path = self::ROOT;

                for ($i = substr_count($m[1], '../'); $i > 0; $i--) {
                    $path = dirname($path);
                }

                $path = ltrim(($path === '.' ? '' : $path).'/'.$m[2], '/');

                return 'href="'.self::REPOSITORY.'/blob/main/'.$path.($m[3] ?? '').'"';
            },
            $html,
        );
    }

    private function titleOf(string $markdown, string $fallback): string
    {
        return preg_match('/^#\s+(.+)$/m', $markdown, $m)
            ? trim($m[1])
            : Str::headline($fallback);
    }

    /** @return array<int, array{slug: string, title: string}> */
    private function contents(): array
    {
        $pages = [];

        foreach (glob(base_path(self::ROOT.'/*.md')) ?: [] as $file) {
            $slug = basename($file, '.md');

            $pages[] = [
                'slug' => $slug,
                'title' => $this->titleOf((string) file_get_contents($file), $slug),
            ];
        }

        // index first, then alphabetical: the reading order is the order somebody
        // arriving with a question would want, not the order the files happen to be in.
        usort($pages, fn (array $a, array $b) => $a['slug'] === 'index' ? -1
            : ($b['slug'] === 'index' ? 1 : strcmp($a['title'], $b['title'])));

        return $pages;
    }
}
