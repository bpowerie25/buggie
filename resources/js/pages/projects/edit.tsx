import { Button } from '@/components/button';
import { Field, Input, Textarea } from '@/components/field';
import { WorkflowEditor } from '@/components/workflow-editor';
import { AppLayout } from '@/layouts/app-layout';
import type { ProjectSummary } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
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

interface VersionRow {
    id: number;
    name: string;
    description: string | null;
    released_at: string | null;
    issues_count: number;
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
    inboundAddress,
    versions = [],
    branding,
}: {
    project: ProjectSummary;
    widgetKeys: WidgetKeyRow[];
    statuses: StatusRow[];
    categories: { value: StatusRow['category']; label: string; open: boolean }[];
    inboundAddress: string;
    versions?: VersionRow[];
    branding: { name: string | null; color: string | null; logo: string | null; placeholder: string };
}) {
    const { data, setData, put, processing, errors } = useForm({
        name: project.name,
        description: project.description ?? '',
        site_url: project.site_url ?? '',
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

                <Field
                    label="Where the reporter runs"
                    error={errors.site_url}
                    hint="Usually the UAT or staging site. New widget keys start locked to this origin; existing keys keep their own allowlist."
                >
                    <Input
                        type="url"
                        value={data.site_url}
                        placeholder="https://uat.acme.com"
                        onChange={(e) => setData('site_url', e.target.value)}
                    />
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

            <Versions versions={versions} project={project} />

            <Branding project={project} branding={branding} />

            <ImportSection project={project} />

            <section className="mt-12 max-w-2xl">
                <h2 className="text-sm font-semibold text-ink">File issues by email</h2>
                <p className="mt-1 text-sm text-ink-muted">
                    Anything sent here becomes an issue in this project. The subject is the
                    title, the body is the description, and attachments come across. Give it
                    to a client who would rather email than sign in.
                </p>

                <CopyRow value={inboundAddress} label="Copy email address" />

                <p className="mt-2 text-xs text-ink-subtle">
                    Treat it as unlisted. Anyone who has it can file into this project, so it
                    is random rather than derived from the project name.
                </p>
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

/** A value with a copy button. Used for the widget snippet and the inbound address. */
function CopyRow({ value, label }: { value: string; label: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <div className="mt-3 flex items-start gap-2">
            <pre className="min-w-0 flex-1 overflow-x-auto rounded-lg bg-surface p-2.5 font-mono text-[11px] text-ink-muted">
                {value}
            </pre>
            <button
                type="button"
                aria-label={label}
                onClick={() => {
                    navigator.clipboard?.writeText(value);
                    setCopied(true);
                    setTimeout(() => setCopied(false), 1500);
                }}
                className="rounded-lg border border-border p-2 text-ink-subtle transition hover:text-ink"
            >
                {copied ? <Check className="size-3.5 text-success" /> : <Copy className="size-3.5" />}
            </button>
        </div>
    );
}

function WidgetKeyCard({ widgetKey }: { widgetKey: WidgetKeyRow }) {
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

            <CopyRow value={widgetKey.snippet} label="Copy snippet" />

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <Field
                    label="Allowed origins"
                    hint="One per line. Wildcards like https://*.acme.com work. Leave empty to accept any origin."
                >
                    <Textarea
                        value={origins}
                        rows={3}
                        spellCheck={false}
                        placeholder="https://uat.acme.com"
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
                    <p className="pl-6 text-xs text-ink-subtle">
                        Off means the reporter is never shown one and none is stored.
                    </p>
                    <label className="flex items-center gap-2 text-sm text-ink-muted">
                        <input
                            type="checkbox"
                            checked={widgetKey.require_email}
                            onChange={(e) => save({ require_email: e.target.checked })}
                            className="rounded border-border-strong"
                        />
                        Require an email address
                    </label>
                    <p className="pl-6 text-xs text-ink-subtle">
                        Without one you cannot reply, and the reporter gets no link to
                        follow their own bug.
                    </p>
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

/**
 * Releases for this project.
 *
 * Marking one released stamps today rather than asking for a date: a date typed by
 * hand is a date somebody gets wrong, and "released" almost always means "now".
 */
function Versions({
    versions,
    project,
}: {
    versions: VersionRow[];
    project: ProjectSummary;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        description: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/projects/${project.slug}/versions`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    }

    return (
        <section className="mt-12 max-w-2xl">
            <h2 className="text-sm font-semibold text-ink">Releases</h2>
            <p className="mt-1 text-sm text-ink-muted">
                Group issues into a release so you can tell a client what changed. Issues
                are put in one from their own page.
            </p>

            {versions.length > 0 && (
                <ul className="mt-4 divide-y divide-border rounded-xl border border-border bg-raised">
                    {versions.map((version) => (
                        <li key={version.id} className="flex items-center gap-3 px-4 py-3">
                            <Link
                                href={`/projects/${project.slug}/versions/${version.id}`}
                                className="min-w-0 flex-1"
                            >
                                <span className="block truncate text-sm text-ink">
                                    {version.name}
                                </span>
                                <span className="text-xs text-ink-subtle">
                                    {version.issues_count} issue
                                    {version.issues_count === 1 ? '' : 's'}
                                    {version.released_at
                                        ? ` · released ${version.released_at}`
                                        : ' · unreleased'}
                                </span>
                            </Link>

                            <button
                                type="button"
                                onClick={() =>
                                    router.patch(
                                        `/projects/${project.slug}/versions/${version.id}`,
                                        { released: !version.released_at },
                                        { preserveScroll: true },
                                    )
                                }
                                className="shrink-0 rounded-md border border-border px-2 py-1 text-xs text-ink-muted transition hover:text-ink"
                            >
                                {version.released_at ? 'Unrelease' : 'Release'}
                            </button>

                            <button
                                type="button"
                                aria-label={`Delete ${version.name}`}
                                onClick={() =>
                                    router.delete(
                                        `/projects/${project.slug}/versions/${version.id}`,
                                        { preserveScroll: true },
                                    )
                                }
                                className="shrink-0 rounded p-1 text-ink-subtle transition hover:text-danger"
                            >
                                <Trash2 className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <form onSubmit={submit} className="mt-4 flex flex-wrap items-end gap-3">
                <div className="flex-1">
                    <Field label="New release" error={errors.name}>
                        <Input
                            value={data.name}
                            placeholder="2.4.1"
                            onChange={(e) => setData('name', e.target.value)}
                        />
                    </Field>
                </div>

                <Button type="submit" size="sm" disabled={processing || data.name === ''}>
                    <Plus className="size-4" />
                    Add
                </Button>
            </form>
        </section>
    );
}

/**
 * Bringing a backlog over from another tracker.
 *
 * Nobody moves tracker without their history, so this is less a feature than the
 * thing that makes moving possible at all.
 */
function ImportSection({ project }: { project: ProjectSummary }) {
    const { data, setData, post, processing, errors } = useForm({ file: null as File | null });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/projects/${project.slug}/imports`, { forceFormData: true });
    }

    return (
        <section className="mt-12 max-w-2xl">
            <h2 className="text-sm font-semibold text-ink">Import from another tracker</h2>
            <p className="mt-1 text-sm text-ink-muted">
                A CSV export from Jira or MantisBT, or a spreadsheet of your own. You will
                see what it is going to create before anything is created.
            </p>

            <form onSubmit={submit} className="mt-4 flex flex-wrap items-end gap-3">
                <div className="flex-1">
                    <Field label="CSV file" error={errors.file}>
                        <input
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                            className="w-full rounded-lg border border-border bg-raised px-3 py-2 text-sm text-ink-muted file:mr-3 file:rounded-md file:border-0 file:bg-surface file:px-2 file:py-1 file:text-xs file:text-ink"
                        />
                    </Field>
                </div>

                <Button type="submit" size="sm" disabled={processing || !data.file}>
                    Upload
                </Button>
            </form>
        </section>
    );
}

/**
 * What a client's own customers see.
 *
 * The person following a link about a broken checkout was using a shop. They should
 * see the shop — not the agency that built it, and not the tracker the agency
 * happens to use.
 */
function Branding({
    project,
    branding,
}: {
    project: ProjectSummary;
    branding: { name: string | null; color: string | null; logo: string | null; placeholder: string };
}) {
    const { data, setData, post, processing, errors } = useForm({
        brand_name: branding.name ?? '',
        brand_color: branding.color ?? '',
        logo: null as File | null,
        remove_logo: false as boolean,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/projects/${project.slug}/branding`, { forceFormData: true, preserveScroll: true });
    }

    return (
        <section className="mt-12 max-w-2xl">
            <h2 className="text-sm font-semibold text-ink">How this looks to a reporter</h2>
            <p className="mt-1 text-sm text-ink-muted">
                Whoever reports a bug sees this on the reporter panel and on the page where
                they follow their own report. Leave it blank and the project name is used.
            </p>

            <form onSubmit={submit} className="mt-4 space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Name"
                        error={errors.brand_name}
                        hint="Usually your client's name, not yours."
                    >
                        <Input
                            value={data.brand_name}
                            placeholder={branding.placeholder}
                            onChange={(e) => setData('brand_name', e.target.value)}
                        />
                    </Field>

                    <Field label="Accent colour" error={errors.brand_color}>
                        <div className="flex items-center gap-2">
                            <input
                                type="color"
                                value={data.brand_color || '#6366f1'}
                                aria-label="Accent colour"
                                onChange={(e) => setData('brand_color', e.target.value)}
                                className="h-9 w-12 cursor-pointer rounded-lg border border-border bg-raised"
                            />
                            <Input
                                value={data.brand_color}
                                placeholder="#6366f1"
                                className="font-mono"
                                onChange={(e) => setData('brand_color', e.target.value)}
                            />
                        </div>
                    </Field>
                </div>

                <Field label="Logo" error={errors.logo} hint="PNG, JPG, WebP or SVG. Up to 512KB.">
                    <div className="flex flex-wrap items-center gap-3">
                        {branding.logo && !data.remove_logo && (
                            <img
                                src={branding.logo}
                                alt="Current logo"
                                className="h-8 w-auto max-w-[8rem] rounded bg-surface object-contain p-1"
                            />
                        )}

                        <input
                            type="file"
                            accept="image/png,image/jpeg,image/webp,image/svg+xml"
                            onChange={(e) => setData('logo', e.target.files?.[0] ?? null)}
                            className="flex-1 rounded-lg border border-border bg-raised px-3 py-2 text-sm text-ink-muted file:mr-3 file:rounded-md file:border-0 file:bg-surface file:px-2 file:py-1 file:text-xs file:text-ink"
                        />

                        {branding.logo && (
                            <label className="flex items-center gap-1.5 text-xs text-ink-muted">
                                <input
                                    type="checkbox"
                                    checked={data.remove_logo}
                                    onChange={(e) => setData('remove_logo', e.target.checked)}
                                />
                                Remove
                            </label>
                        )}
                    </div>
                </Field>

                <Button type="submit" size="sm" disabled={processing}>
                    Save branding
                </Button>
            </form>
        </section>
    );
}
