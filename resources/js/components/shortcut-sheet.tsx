const groups: { heading: string; items: [string, string][] }[] = [
    {
        heading: 'Anywhere',
        items: [
            ['mod K', 'Command palette'],
            ['c', 'New issue'],
            ['/', 'Focus search'],
            ['?', 'This sheet'],
        ],
    },
    {
        heading: 'Go to',
        items: [
            ['g i', 'Issues'],
            ['g b', 'Board'],
            ['g l', 'List'],
            ['g p', 'Projects'],
            ['g a', 'Assigned to me'],
        ],
    },
    {
        heading: 'Issue list',
        items: [
            ['j', 'Move down'],
            ['k', 'Move up'],
            ['enter', 'Open issue'],
            ['e', 'Status'],
            ['a', 'Assignee'],
            ['p', 'Priority'],
            ['x', 'Select'],
        ],
    },
    {
        heading: 'Editing',
        items: [
            ['mod enter', 'Submit comment'],
            ['esc', 'Cancel / close'],
        ],
    },
];

export function ShortcutSheet({ onClose }: { onClose: () => void }) {
    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            onClick={onClose}
        >
            <div
                role="dialog"
                aria-label="Keyboard shortcuts"
                onClick={(e) => e.stopPropagation()}
                className="w-full max-w-lg rounded-xl border border-border bg-raised p-5 shadow-2xl"
            >
                <h2 className="text-sm font-semibold text-ink">Keyboard shortcuts</h2>

                <div className="mt-4 grid gap-x-8 gap-y-5 sm:grid-cols-2">
                    {groups.map((group) => (
                        <section key={group.heading}>
                            <h3 className="text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">
                                {group.heading}
                            </h3>
                            <dl className="mt-2 space-y-1.5">
                                {group.items.map(([keys, label]) => (
                                    <div key={keys} className="flex items-center gap-3">
                                        <dt className="flex w-24 shrink-0 gap-1">
                                            {keys.split(' ').map((key, i) => (
                                                <kbd
                                                    key={`${key}-${i}`}
                                                    className="rounded border border-border bg-surface px-1.5 py-0.5 font-mono text-[10px] text-ink-muted"
                                                >
                                                    {key === 'mod' ? '⌘' : key}
                                                </kbd>
                                            ))}
                                        </dt>
                                        <dd className="text-xs text-ink-muted">{label}</dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    ))}
                </div>

                <p className="mt-5 text-[11px] text-ink-subtle">
                    Shortcuts are ignored while you are typing in a field.
                </p>
            </div>
        </div>
    );
}
