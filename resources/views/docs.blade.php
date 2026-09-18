<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · Buggie documentation</title>

    {{-- A plain Blade page, not Inertia: documentation should render without
         JavaScript, be readable by a crawler, and survive the application being
         broken, which is exactly when somebody reaches for the docs. --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('buggie.theme');
                if (t === 'dark' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full bg-canvas text-ink">
    <header class="border-b border-border">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-4">
            <a href="/" class="text-sm font-semibold text-ink">Buggie</a>
            <nav class="flex items-center gap-4 text-sm text-ink-muted">
                <a href="/docs" class="transition hover:text-ink">Documentation</a>
                <a href="https://github.com/bpowerie25/buggie" class="transition hover:text-ink">Source</a>
                <a href="/login" class="transition hover:text-ink">Sign in</a>
            </nav>
        </div>
    </header>

    <div class="mx-auto flex max-w-6xl flex-col gap-10 px-6 py-10 lg:flex-row">
        <aside class="lg:w-56 lg:shrink-0">
            <nav class="space-y-0.5 lg:sticky lg:top-10">
                @foreach ($pages as $page)
                    <a href="/docs/{{ $page['slug'] === 'index' ? '' : $page['slug'] }}"
                       class="block truncate rounded-lg px-2.5 py-1.5 text-sm transition {{ $page['slug'] === $current ? 'bg-accent-soft font-medium text-accent' : 'text-ink-muted hover:bg-surface hover:text-ink' }}">
                        {{ $page['title'] }}
                    </a>
                @endforeach
            </nav>
        </aside>

        {{-- Styling the rendered Markdown by descendant selector, since the HTML comes
             from CommonMark and carries no classes of its own. --}}
        <main class="min-w-0 flex-1 text-[15px] leading-relaxed text-ink-muted
                     [&_a]:text-accent [&_a]:underline [&_a]:underline-offset-2
                     [&_blockquote]:border-l-2 [&_blockquote]:border-border [&_blockquote]:pl-4 [&_blockquote]:text-ink-subtle
                     [&_code]:rounded [&_code]:bg-surface [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[13px]
                     [&_h1]:mt-0 [&_h1]:mb-6 [&_h1]:text-3xl [&_h1]:font-semibold [&_h1]:tracking-tight [&_h1]:text-ink
                     [&_h2]:mt-10 [&_h2]:mb-3 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-ink
                     [&_h3]:mt-8 [&_h3]:mb-2 [&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-ink
                     [&_hr]:my-10 [&_hr]:border-border
                     [&_li]:my-1 [&_ol]:my-4 [&_ol]:list-decimal [&_ol]:pl-6
                     [&_p]:my-4
                     [&_pre]:my-5 [&_pre]:overflow-x-auto [&_pre]:rounded-xl [&_pre]:border [&_pre]:border-border [&_pre]:bg-surface [&_pre]:p-4 [&_pre]:text-[13px]
                     [&_pre_code]:bg-transparent [&_pre_code]:p-0
                     [&_strong]:font-semibold [&_strong]:text-ink
                     [&_table]:my-5 [&_table]:w-full [&_table]:text-left [&_table]:text-sm
                     [&_td]:border-t [&_td]:border-border [&_td]:py-2 [&_td]:pr-4 [&_td]:align-top
                     [&_th]:pb-2 [&_th]:pr-4 [&_th]:font-semibold [&_th]:text-ink
                     [&_ul]:my-4 [&_ul]:list-disc [&_ul]:pl-6">
            {!! $html !!}
        </main>
    </div>
</body>
</html>
