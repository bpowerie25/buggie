import { Button } from '@/components/button';
import { Field, Input, Textarea } from '@/components/field';
import { WorkflowEditor } from '@/components/workflow-editor';
import { AppLayout } from '@/layouts/app-layout';
import type { ProjectSummary } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, Copy, Plus, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface WidgetKeyRow {
    id: number;
    public_key: string;
    allowed_origins: string[];
    mode: string;
    require_email: boolean;
    capture_screenshot: boolean;
    is_active: boolean;
    last_used_at: string | null;
    snippet: string;
}

interface StatusRow {
    id: number;
    name: string;
    category: 'backlog' | 'unstarted' | 'started' | 'done' | 'canceled';
    color: string;
    position: number;
    is_default: boolean;
    open: boolean;
    issues_count: number;
}

export default function EditProject({
    project,
    widgetKeys,
    statuses,
    categories,
}: {
    project: ProjectSummary;
    widgetKeys: WidgetKeyRow[];
    statuses: StatusRow[];
    categories: { value: StatusRow['category']; label: string; open: boolean }[];
}) {
    const { data, setData, put, processing, errors } = useForm({
        name: project.name,
        description: project.description ?? '',
        is_archived: project.is_archived ?? false,
    });
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    function submit(e: FormEvent) {
        e.preventDefault();
        put(`/projects/${project.slug}`);
    }

    return (
        <AppLayout title={`${project.name} settings`}>
            <Head title={`${project.name} settings`} />

            <form onSubmit={submit} className="max-w-lg space-y-4">
                <Field label="Name" error={errors.name}>
                    <Input
                        value={data.name}
                        required
                        onChange={(e) => setData('name', e.target.value)}
                    />
                </Field>

                <Field
                    label="Issue key"
                    hint="Fixed after creation — existing issue keys reference it."
                >
                    <Input value={project.key} disabled className="font-mono" />
                </Field>

                <Field label="Description" error={errors.description}>
                    <Textarea
                        value={data.description}
                        rows={3}
                        onChange={(e) => setData('description', e.target.value)}
                    />
                </Field>

                <label className="flex items-center gap-2 text-sm text-ink-muted">
                    <input
                        type="checkbox"
                        checked={data.is_archived}
                        onChange={(e) => setData('is_archived', e.target.checked)}
                        className="rounded border-border-strong"
                    />
                    Archive this project
                </label>

                <Button type="submit" disabled={processing}>
                    {processing ? 'Saving…' : 'Save changes'}
                </Button>
            </form>

            <div className="mt-12">
                <WorkflowEditor
                    projectSlug={project.slug}
                    statuses={statuses}
                    categories={categories}
                />
            </div>

            <section className="mt-12 max-w-2xl">
                <h2 className="text-sm font-semibold text-ink">Bug reporter widget</h2>
                <p className="mt-1 text-sm text-pretty text-ink-muted">
                    Drop this into the app and reports arrive with the screenshot, console,
                    failing request and page address already attached. Reports land in the
                    triage inbox, not the backlog.
                </p>

                {widgetKeys.length === 0 ? (
                    <Button
                        size="sm"
                        className="mt-4"
                        onClick={() =>
                            router.post(`/projects/${project.slug}/widget-keys`, {}, { preserveScroll: true })
                        }
                    >
                        <Plus className="size-4" />
                        Create a widget key
                    </Button>
                ) : (
                    <div className="mt-4 space-y-4">
                        {widgetKeys.map((key) => (
                            <WidgetKeyCard key={key.id} widgetKey={key} />
                        ))}
                    </div>
                )}
            </section>

            <section className="mt-12 max-w-lg rounded-xl border border-danger/30 bg-danger-soft p-4">
                <h2 className="text-sm font-semibold text-ink">Delete project</h2>
                <p className="mt-1 text-sm text-ink-muted">
                    Soft-deletes the project and hides its issues. Recoverable by an
                    administrator.
                </p>

                {confirmingDelete ? (
                    <div className="mt-3 flex gap-2">
                        <Button
                            variant="danger"
                            size="sm"
                            onClick={() => router.delete(`/projects/${project.slug}`)}
                        >
                            Yes, delete {project.key}
                        </Button>
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => setConfirmingDelete(false)}
                        >
                            Cancel
                        </Button>
                    </div>
                ) : (
                    <Button
                        variant="danger"
                        size="sm"
                        className="mt-3"
                        onClick={() => setConfirmingDelete(true)}
                    >
                        Delete project
                    </Button>
                )}
            </section>
        </AppLayout>
    );
}

function WidgetKeyCard({ widgetKey }: { widgetKey: WidgetKeyRow }) {
    const [copied, setCopied] = useState(false);
    const [origins, setOrigins] = useState(widgetKey.allowed_origins.join('\n'));

    function save(changes: Record<string, unknown>) {
        router.patch(
            `/widget-keys/${widgetKey.id}`,
            {
                allowed_origins: origins.split('\n').map((o) => o.trim()).filter(Boolean),
                mode: widgetKey.mode,
                require_email: widgetKey.require_email,
                capture_screenshot: widgetKey.capture_screenshot,
                is_active: widgetKey.is_active,
                ...changes,
            },
            { preserveScroll: true },
        );
    }

    return (
        <div className="rounded-xl border border-border bg-raised p-4">
            <div className="flex items-center gap-2">
                <code className="font-mono text-xs text-ink-muted">{widgetKey.public_key}</code>
                {!widgetKey.is_active && (
                    <span className="rounded bg-surface px-1.5 py-0.5 text-[10px] text-ink-subtle">
                        Disabled
                    </span>
                )}
                <button
                    type="button"
                    onClick={() =>
                        router.delete(`/widget-keys/${widgetKey.id}`, { preserveScroll: true })
                    }
                    aria-label="Revoke key"
                    className="ml-auto rounded p-1 text-ink-subtle transition hover:text-danger"
                >
                    <Trash2 className="size-3.5" />
                </button>
            </div>

            <div className="mt-3 flex items-start gap-2">
                <pre className="min-w-0 flex-1 overflow-x-auto rounded-lg bg-surface p-2.5 font-mono text-[11px] text-ink-muted">
                    {widgetKey.snippet}
                </pre>
                <button
                    type="button"
                    aria-label="Copy snippet"
                    onClick={() => {
                        navigator.clipboard?.writeText(widgetKey.snippet);
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1500);
                    }}
                    className="rounded-lg border border-border p-2 text-ink-subtle transition hover:text-ink"
                >
                    {copied ? <Check className="size-3.5 text-success" /> : <Copy className="size-3.5" />}
                </button>
            </div>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <Field
                    label="Allowed origins"
                    hint="One per line. Wildcards like https://*.acme.com work. Leave empty to accept any origin."
                >
                    <Textarea
                        value={origins}
                        rows={3}
                        spellCheck={false}
                        placeholder="https://acme.com"
                        className="font-mono text-xs"
                        onChange={(e) => setOrigins(e.target.value)}
                        onBlur={() => save({})}
                    />
                </Field>

                <div className="space-y-2 pt-6">
                    <label className="flex items-center gap-2 text-sm text-ink-muted">
                        <input
                            type="checkbox"
                            checked={widgetKey.capture_screenshot}
                            onChange={(e) => save({ capture_screenshot: e.target.checked })}
                            className="rounded border-border-strong"
                        />
                        Offer a screenshot
                    </label>
                    <label className="flex items-center gap-2 text-sm text-ink-muted">
                        <input
                            type="checkbox"
                            checked={widgetKey.require_email}
                            onChange={(e) => save({ require_email: e.target.checked })}
                            className="rounded border-border-strong"
                        />
                        Require an email address
                    </label>
                    <label className="flex items-center gap-2 text-sm text-ink-muted">
                        <input
                            type="checkbox"
                            checked={widgetKey.is_active}
                            onChange={(e) => save({ is_active: e.target.checked })}
                            className="rounded border-border-strong"
                        />
                        Accepting reports
                    </label>
                </div>
            </div>

            <p className="mt-3 text-[11px] text-ink-subtle">
                Passwords and any element marked <code>data-buggie-redact</code> are never
                captured, credential-shaped query parameters are stripped from URLs, and the
                reporter sees the image before it is sent.
            </p>
        </div>
    );
}
