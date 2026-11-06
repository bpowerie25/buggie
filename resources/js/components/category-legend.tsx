import type { ProjectCategory } from '@/types';

/**
 * The colour key for projects, doubling as the filter: click a category to show only
 * those projects, click again to clear. "None" is the projects nobody has categorised.
 */
export function CategoryLegend({
    categories,
    selected,
    onChange,
    hasUncategorised,
}: {
    categories: ProjectCategory[];
    /** Category ids, or 0 for uncategorised. Empty means no filter. */
    selected: number[];
    onChange: (selected: number[]) => void;
    hasUncategorised: boolean;
}) {
    if (categories.length === 0) return null;

    const toggle = (id: number) =>
        onChange(selected.includes(id) ? selected.filter((s) => s !== id) : [...selected, id]);

    const chip = (id: number, name: string, color: string | null) => {
        const on = selected.includes(id);

        return (
            <button
                key={id}
                type="button"
                aria-pressed={on}
                onClick={() => toggle(id)}
                className={`flex cursor-pointer items-center gap-1.5 rounded-lg border px-2 py-1 text-xs transition ${
                    on
                        ? 'border-accent bg-accent-soft font-medium text-accent'
                        : 'border-border text-ink-muted hover:border-border-strong hover:bg-raised hover:text-ink'
                }`}
            >
                <span
                    aria-hidden
                    className="size-2.5 rounded-full"
                    style={{ backgroundColor: color ?? 'transparent', border: color ? undefined : '1px dashed currentColor' }}
                />
                {name}
            </button>
        );
    };

    return (
        <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Project categories">
            {categories.map((c) => chip(c.id, c.name, c.color))}
            {hasUncategorised && chip(0, 'None', null)}
            {selected.length > 0 && (
                <button
                    type="button"
                    onClick={() => onChange([])}
                    className="rounded-lg px-2 py-1 text-xs text-ink-subtle transition hover:text-ink"
                >
                    Clear
                </button>
            )}
        </div>
    );
}
