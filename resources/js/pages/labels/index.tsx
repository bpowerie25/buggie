import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AppLayout } from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';

interface Label {
    id: number;
    name: string;
    color: string;
    description: string | null;
    issues_count: number;
}

const palette = [
    '#ef4444', '#f59e0b', '#10b981', '#3b82f6',
    '#8b5cf6', '#ec4899', '#64748b', '#0ea5e9',
];

export default function LabelsIndex({ labels }: { labels: Label[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        color: palette[0],
        description: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/labels', { preserveScroll: true, onSuccess: () => reset() });
    }

    return (
        <AppLayout title="Labels">
            <Head title="Labels" />

            <p className="max-w-xl text-sm text-pretty text-ink-muted">
                Labels are shared across every project in this workspace, so
                &ldquo;regression&rdquo; means the same thing everywhere.
            </p>

            <form onSubmit={submit} className="mt-6 flex max-w-2xl flex-wrap items-end gap-3">
                <div className="min-w-40 flex-1">
                    <Field label="Name" error={errors.name}>
                        <Input
                            value={data.name}
                            required
                            placeholder="regression"
                            onChange={(e) => setData('name', e.target.value)}
                        />
                    </Field>
                </div>

                <div className="min-w-48 flex-1">
                    <Field label="Description">
                        <Input
                            value={data.description}
                            placeholder="Optional"
                            onChange={(e) => setData('description', e.target.value)}
                        />
                    </Field>
                </div>

                <fieldset className="flex items-center gap-1">
                    <legend className="sr-only">Colour</legend>
                    {palette.map((color) => (
                        <button
                            key={color}
                            type="button"
                            aria-label={`Colour ${color}`}
                            aria-pressed={data.color === color}
                            onClick={() => setData('color', color)}
                            className={`size-6 rounded-full transition ${
                                data.color === color
                                    ? 'ring-2 ring-accent ring-offset-2 ring-offset-canvas'
                                    : ''
                            }`}
                            style={{ backgroundColor: color }}
                        />
                    ))}
                </fieldset>

                <Button type="submit" disabled={processing || !data.name}>
                    Add label
                </Button>
            </form>

            {labels.length > 0 && (
                <ul className="mt-8 max-w-2xl divide-y divide-border overflow-hidden rounded-xl border border-border bg-raised">
                    {labels.map((label) => (
                        <li key={label.id} className="flex items-center gap-3 px-4 py-2.5">
                            <span
                                aria-hidden
                                className="size-3 shrink-0 rounded-full"
                                style={{ backgroundColor: label.color }}
                            />
                            <span className="text-sm font-medium text-ink">{label.name}</span>
                            <span className="min-w-0 flex-1 truncate text-xs text-ink-muted">
                                {label.description}
                            </span>
                            <span className="shrink-0 text-xs text-ink-subtle">
                                {label.issues_count} issue
                                {label.issues_count === 1 ? '' : 's'}
                            </span>
                            <button
                                type="button"
                                aria-label={`Delete ${label.name}`}
                                onClick={() =>
                                    router.delete(`/labels/${label.id}`, {
                                        preserveScroll: true,
                                    })
                                }
                                className="rounded p-1 text-ink-subtle transition hover:text-danger"
                            >
                                <Trash2 className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </AppLayout>
    );
}
