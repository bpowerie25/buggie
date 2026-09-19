import { useEffect, useState, type ChangeEvent } from 'react';

export interface CustomFieldDefinition {
    key: string;
    name: string;
    type: string;
    options: string[];
    required: boolean;
}

export interface CustomFieldWithValue extends CustomFieldDefinition {
    id: number;
    visible_to_client: boolean;
    value: string | null;
}

const INPUT =
    'w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-subtle';

/**
 * One input for one custom field.
 *
 * Shared between filing an issue and editing one, so the two cannot disagree about
 * what a "choice" field looks like or which values a date accepts.
 *
 * A select always offers a blank option unless the field is required: without it, a
 * dropdown silently commits to its first choice the moment the form is submitted,
 * which is how every issue ends up marked "Production".
 */
export function CustomFieldInput({
    field,
    value,
    onChange,
    compact = false,
}: {
    field: CustomFieldDefinition;
    value: string | null;
    onChange: (value: string | null) => void;
    compact?: boolean;
}) {
    const className = compact
        ? 'w-full rounded-md border border-transparent bg-transparent px-1.5 py-1 text-sm text-ink transition hover:border-border'
        : INPUT;

    /*
     * Typed fields commit when you leave them, not on every keystroke.
     *
     * On the issue sidebar `onChange` is a PATCH, so typing "Safari 18" would have
     * sent nine of them — nine activity entries, nine webhook deliveries, and the
     * last one winning by luck. A dropdown, checkbox or date picker changes in one
     * go, so those still commit immediately.
     */
    const [draft, setDraft] = useState(value ?? '');

    // Follows the value when it changes underneath — another tab, or a reset after
    // save — but not while it is being typed into.
    useEffect(() => setDraft(value ?? ''), [value]);

    const commit = (next: string) => onChange(next === '' ? null : next);

    const typed = {
        value: draft,
        onChange: (e: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
            setDraft(e.target.value),
        onBlur: () => {
            if (draft !== (value ?? '')) commit(draft);
        },
    };

    const handle = (e: ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
        commit(e.target.value);

    switch (field.type) {
        case 'select':
            return (
                <select value={value ?? ''} onChange={handle} className={className}>
                    {!field.required && <option value="">—</option>}
                    {field.options.map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                </select>
            );

        case 'checkbox':
            return (
                <label className="flex items-center gap-2 text-sm text-ink-muted">
                    <input
                        type="checkbox"
                        checked={value === '1'}
                        onChange={(e) => onChange(e.target.checked ? '1' : '0')}
                    />
                    Yes
                </label>
            );

        case 'multiline':
            return <textarea rows={3} {...typed} className={compact ? className : INPUT} />;

        case 'number':
            return <input type="number" step="any" {...typed} className={className} />;

        case 'date':
            return <input type="date" value={value ?? ''} onChange={handle} className={className} />;

        case 'url':
            return (
                <input type="url" inputMode="url" placeholder="https://" {...typed} className={className} />
            );

        default:
            return <input type="text" {...typed} className={className} />;
    }
}

/** How a saved value reads when nobody is editing it. */
export function CustomFieldValueText({ field }: { field: CustomFieldWithValue }) {
    if (field.value === null || field.value === '') {
        return <span className="text-sm text-ink-subtle">—</span>;
    }

    if (field.type === 'checkbox') {
        return <span className="text-sm text-ink">{field.value === '1' ? 'Yes' : 'No'}</span>;
    }

    if (field.type === 'url') {
        return (
            // rel is not optional: the target page is chosen by whoever filled the
            // field in, and window.opener is a handed-over tab.
            <a
                href={field.value}
                target="_blank"
                rel="noopener noreferrer"
                className="text-sm text-accent underline underline-offset-2"
            >
                {field.value}
            </a>
        );
    }

    return <span className="text-sm whitespace-pre-wrap text-ink">{field.value}</span>;
}
