import { Button } from '@/components/button';
import { Field, Input, Textarea } from '@/components/field';
import { WorkflowEditor } from '@/components/workflow-editor';
import { AppLayout } from '@/layouts/app-layout';
import type { ProjectSummary } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Check, Copy, Plus, Trash2 } from 'lucide-react';
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
    secret_rotated_at: string | null;
}

interface PhaseRow {
    id: number;
    name: string;
    issues_count: number;
}

interface CustomFieldRow {
    id: number;
    name: string;
    key: string;
    type: string;
    options: string[];
    required: boolean;
    visible_to_client: boolean;
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
    inboundReason = null,
    versions = [],
    phases = [],
    customFields = [],
    fieldTypes = [],
    branding,
    clientWait,
    widgetModes = [],
    revealedSecret = null,
    clientTimeline = 'off',
}: {
    project: ProjectSummary;
    widgetKeys: WidgetKeyRow[];
    statuses: StatusRow[];
    categories: { value: StatusRow['category']; label: string; open: boolean }[];
    inboundAddress: string;
    inboundReason?: string | null;
    versions?: VersionRow[];
    phases?: PhaseRow[];
    customFields?: CustomFieldRow[];
    fieldTypes?: { value: string; label: string; has_options: boolean }[];
    branding: { name: string | null; color: string | null; logo: string | null; placeholder: string };
    clientWait: { reminder_days: number | null; close_days: number | null; has_status: boolean };
    widgetModes?: { value: string; label: string }[];
    /** Present only on the page load straight after creating or rotating a secret. */
    revealedSecret?: { key: string; secret: string } | null;
    /** Whether clients who hold this project can see its timeline. */
    clientTimeline?: 'off' | 'issues' | 'phases';
}) {
    const { data, setData, put, processing, errors } = useForm({
        name: project.name,
        description: project.description ?? '',
        site_url: project.site_url ?? '',
        is_archived: project.is_archived ?? false,
        awaiting_reminder_days: clientWait.reminder_days === null ? '' : String(clientWait.reminder_days),
        awaiting_close_days: clientWait.close_days === null ? '' : String(clientWait.close_days),
        client_timeline: clientTimeline,
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

                <fieldset className="space-y-3 rounded-lg border border-border p-3">
                    <legend className="px-1 text-sm font-medium text-ink">Waiting on the client</legend>
                    <p className="text-xs text-ink-subtle">
                        {clientWait.has_status
                            ? 'For issues in the awaiting-client status after Reply & await client. Leave blank to switch off.'
                            : 'Mark a status as awaiting client in the workflow below to use these.'}
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Remind the client after (days)" error={errors.awaiting_reminder_days}>
                            <Input
                                type="number"
                                min={1}
                                max={365}
                                value={data.awaiting_reminder_days}
                                placeholder="Off"
                                onChange={(e) => setData('awaiting_reminder_days', e.target.value)}
                            />
                        </Field>
                        <Field label="Close with no reply after (days)" error={errors.awaiting_close_days}>
                            <Input
                                type="number"
                                min={1}
                                max={365}
                                value={data.awaiting_close_days}
                                placeholder="Off"
                                onChange={(e) => setData('awaiting_close_days', e.target.value)}
                            />
                        </Field>
                    </div>
                </fieldset>

                <fieldset className="space-y-2">
                    <legend className="text-sm text-ink">Timeline for clients</legend>
                    {(
                        [
                            ['off', 'Off', 'Clients who hold this project get no timeline.'],
                            [
                                'phases',
                                'Phases only',
                                'Each phase with its dates and how much is done, and no issues at all. Plan in detail internally; the client sees the shape of the job.',
                            ],
                            [
                                'issues',
                                'Issues they can open',
                                'The issues already shared with them, as bars: no estimates, and the team shown as the workspace unless you choose otherwise.',
                            ],
                        ] as const
                    ).map(([value, label, help]) => (
                        <label key={value} className="flex items-start gap-2 text-sm text-ink-muted">
                            <input
                                type="radio"
                                name="client_timeline"
                                value={value}
                                checked={data.client_timeline === value}
                                onChange={() => setData('client_timeline', value)}
                                className="mt-0.5 border-border-strong"
                            />
                            <span>
                                {label}
                                <span className="block text-xs text-ink-subtle">{help}</span>
                            </span>
                        </label>
                    ))}
                    {data.client_timeline === 'phases' && phases.length === 0 && (
                        <p className="text-xs text-danger">
                            This project has no phases yet, so a client would see an empty timeline. Add
                            them under Phases below.
                        </p>
                    )}
                </fieldset>

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
                            <WidgetKeyCard
                                key={key.id}
                                widgetKey={key}
                                modes={widgetModes}
                                secret={revealedSecret?.key === key.public_key ? revealedSecret.secret : null}
                            />
                        ))}
                    </div>
                )}
            </section>

            <Phases phases={phases} project={project} />

            <Versions versions={versions} project={project} />

            <CustomFields fields={customFields} types={fieldTypes} project={project} />

            <Branding project={project} branding={branding} />

            {/* Importing moved to its own page, which any member of staff can use. */}
            <section className="mt-12 max-w-2xl">
                <h2 className="text-sm font-semibold text-ink">Import</h2>
                <p className="mt-1 text-sm text-ink-muted">
                    Creating or updating issues from a spreadsheet, Jira or MantisBT is on the{' '}
                    <Link href={`/projects/${project.slug}/import`} className="text-accent hover:underline">
                        Import page
                    </Link>
                    , also linked from the project and the issue list.
                </p>
            </section>

            <section className="mt-12 max-w-2xl">
                <h2 className="text-sm font-semibold text-ink">File issues by email</h2>
                <p className="mt-1 text-sm text-ink-muted">
                    Anything sent here becomes an issue in this project. The subject is the
                    title, the body is the description, and attachments come across. Give it
                    to a client who would rather email than sign in.
                </p>

                {inboundReason ? (
                    // No copyable address at all while it would not work. A field you
                    // can copy is a promise that copying it achieves something.
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-600 dark:text-amber-500">
                        Filing issues by email is not set up on this install, so this
                        project has no working address yet. {inboundReason}
                    </p>
                ) : (
                    <CopyRow value={inboundAddress} label="Copy email address" />
                )}

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

function WidgetKeyCard({
    widgetKey,
    modes,
    secret,
}: {
    widgetKey: WidgetKeyRow;
    modes: { value: string; label: string }[];
    /** Only straight after creating or rotating it, and never again. */
    secret: string | null;
}) {
    const [origins, setOrigins] = useState(widgetKey.allowed_origins.join('\n'));
    // Said beside the key rather than only in a toast: a setting that did not save
    // and looks as if it did is how a widget ends up open to every origin.
    const [error, setError] = useState<string | null>(null);

    // Anything other than a validation error — refused, not found, the server or the
    // network — is shown here too, instead of Inertia's full-page error dialog.
    function failures(what: string) {
        return {
            onHttpException: (response: { status: number }) => {
                setError(`${what} (the server answered ${response.status}).`);
                return false;
            },
            onNetworkError: () => {
                setError(`${what}: the server could not be reached.`);
                return false;
            },
        };
    }

    // By public key, which is how the routes bind a widget key. The numeric id 404'd,
    // and every toggle on this card failed while looking as if it had worked.
    function save(changes: Record<string, unknown>) {
        router.patch(
            `/widget-keys/${widgetKey.public_key}`,
            {
                allowed_origins: origins.split('\n').map((o) => o.trim()).filter(Boolean),
                mode: widgetKey.mode,
                require_email: widgetKey.require_email,
                capture_screenshot: widgetKey.capture_screenshot,
                is_active: widgetKey.is_active,
                ...changes,
            },
            {
                preserveScroll: true,
                onSuccess: () => setError(null),
                onError: (errors) =>
                    setError(Object.values(errors)[0] ?? 'Could not save the widget settings.'),
                ...failures('Could not save the widget settings'),
            },
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
                        router.delete(`/widget-keys/${widgetKey.public_key}`, {
                            preserveScroll: true,
                            onError: (errors) =>
                                setError(Object.values(errors)[0] ?? 'Could not revoke the key.'),
                            ...failures('Could not revoke the key'),
                        })
                    }
                    aria-label="Revoke key"
                    className="ml-auto rounded p-1 text-ink-subtle transition hover:text-danger"
                >
                    <Trash2 className="size-3.5" />
                </button>
            </div>

            <CopyRow value={widgetKey.snippet} label="Copy snippet" />

            {error && (
                <p role="alert" className="mt-3 rounded-lg border border-danger/30 bg-danger-soft px-3 py-2 text-xs text-danger">
                    {error}
                </p>
            )}

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

            <IdentitySettings
                widgetKey={widgetKey}
                modes={modes}
                secret={secret}
                onModeChange={(mode) => save({ mode })}
            />

            <p className="mt-3 text-[11px] text-ink-subtle">
                Passwords and any element marked <code>data-buggie-redact</code> are never
                captured, credential-shaped query parameters are stripped from URLs, and the
                reporter sees the image before it is sent.
            </p>
        </div>
    );
}

/**
 * Who reported something, and how sure we are: the key's identity mode, its signing
 * secret, and how to call identify() — with the server-side half, because a hash
 * computed in the browser would be a hash anybody could compute.
 */
function IdentitySettings({
    widgetKey,
    modes,
    secret,
    onModeChange,
}: {
    widgetKey: WidgetKeyRow;
    modes: { value: string; label: string }[];
    secret: string | null;
    onModeChange: (mode: string) => void;
}) {
    const identify = `window.buggie = window.buggie || { q: [] };
window.buggie.q.push(['identify', {
  id: '4821',
  email: 'ann@acme.com',
  name: 'Ann',
  user_hash: '<computed on your server>',
}]);`;

    const php = `// user_hash = HMAC-SHA256 of "id:email", with this key's secret.
$userHash = hash_hmac('sha256', $user->id . ':' . $user->email, getenv('BUGGIE_WIDGET_SECRET'));`;

    const node = `// user_hash = HMAC-SHA256 of "id:email", with this key's secret.
const crypto = require('node:crypto');
const userHash = crypto
  .createHmac('sha256', process.env.BUGGIE_WIDGET_SECRET)
  .update(\`\${user.id}:\${user.email}\`)
  .digest('hex');`;

    return (
        <div className="mt-5 space-y-3 border-t border-border pt-4">
            <Field
                label="Reporter identity"
                hint="Verified means your server signed who the reporter is. Only then — unless the workspace trusts unverified emails — is a report linked to a client, who can then see it."
            >
                <select
                    value={widgetKey.mode}
                    onChange={(e) => onModeChange(e.target.value)}
                    className="w-full rounded-lg border border-border bg-raised px-3 py-2 text-sm text-ink"
                >
                    {modes.map((mode) => (
                        <option key={mode.value} value={mode.value}>
                            {mode.label}
                        </option>
                    ))}
                </select>
            </Field>

            <div>
                <p className="text-sm font-medium text-ink">Signing secret</p>
                {secret ? (
                    <>
                        <p className="mt-1 text-xs text-amber-600 dark:text-amber-500">
                            Copy this now and keep it on your server. It is not shown again.
                        </p>
                        <CopyRow value={secret} label="Copy secret" />
                    </>
                ) : (
                    <p className="mt-1 text-xs text-ink-subtle">
                        Hidden. {widgetKey.secret_rotated_at && `Last set ${new Date(widgetKey.secret_rotated_at).toLocaleDateString()}. `}
                        Rotating makes a new one and stops the old one verifying at once.
                    </p>
                )}
                <Button
                    size="sm"
                    variant="secondary"
                    className="mt-2"
                    onClick={() => router.post(`/widget-keys/${widgetKey.public_key}/secret`, {}, { preserveScroll: true })}
                >
                    Rotate secret
                </Button>
            </div>

            <div>
                <p className="text-sm font-medium text-ink">Identify the reporter</p>
                <p className="mt-1 text-xs text-ink-subtle">
                    On pages where someone is signed in. The email field is then filled in and hidden.
                    Without <code>user_hash</code> the report is <em>identified</em>; with a valid one it is{' '}
                    <em>verified</em>. A wrong hash is recorded as identified and logged.
                </p>
                <CopyRow value={identify} label="Copy identify snippet" />
                <p className="mt-3 text-xs text-ink-subtle">Computing user_hash on your server — PHP:</p>
                <CopyRow value={php} label="Copy PHP example" />
                <p className="mt-3 text-xs text-ink-subtle">Node:</p>
                <CopyRow value={node} label="Copy Node example" />
            </div>
        </div>
    );
}

/**
 * The stages of the job, in the order it runs. The timeline groups issues under them;
 * an issue is put in one from its own page.
 */
function Phases({ phases, project }: { phases: PhaseRow[]; project: ProjectSummary }) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/projects/${project.slug}/phases`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    }

    function move(index: number, by: -1 | 1) {
        const ids = phases.map((phase) => phase.id);
        [ids[index], ids[index + by]] = [ids[index + by], ids[index]];

        router.put(`/projects/${project.slug}/phases/order`, { ids }, { preserveScroll: true });
    }

    function rename(phase: PhaseRow, input: HTMLInputElement) {
        const name = input.value.trim();
        // Put the old name back rather than leave one on screen that was not saved.
        const restore = () => (input.value = phase.name);

        if (name === phase.name) return;
        if (name === '') return restore();

        router.patch(`/projects/${project.slug}/phases/${phase.id}`, { name }, { preserveScroll: true, onError: restore });
    }

    return (
        <section className="mt-12 max-w-2xl">
            <h2 className="text-sm font-semibold text-ink">Phases</h2>
            <p className="mt-1 text-sm text-ink-muted">
                The stages the job runs in, such as Discovery, Design, Build and Launch. The
                timeline groups issues under them in this order, and shows how much of each
                is done. Put an issue in a phase from its own page, or filter with{' '}
                <code className="font-mono text-xs">phase:Design</code>.
            </p>

            {phases.length > 0 && (
                <ul className="mt-4 divide-y divide-border rounded-xl border border-border bg-raised">
                    {phases.map((phase, index) => (
                        <li key={`${phase.id}-${phase.name}`} className="flex items-center gap-2 px-4 py-2">
                            <input
                                defaultValue={phase.name}
                                aria-label={`Rename ${phase.name}`}
                                maxLength={60}
                                onBlur={(e) => rename(phase, e.currentTarget)}
                                onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
                                className="min-w-0 flex-1 rounded-md border border-transparent bg-transparent px-1.5 py-1 text-sm text-ink transition hover:border-border focus:border-border"
                            />
                            <span className="shrink-0 text-xs text-ink-subtle">
                                {phase.issues_count} issue{phase.issues_count === 1 ? '' : 's'}
                            </span>
                            <button
                                type="button"
                                aria-label={`Move ${phase.name} earlier`}
                                disabled={index === 0}
                                onClick={() => move(index, -1)}
                                className="shrink-0 rounded p-1 text-ink-subtle transition hover:text-ink disabled:opacity-30"
                            >
                                <ArrowUp className="size-3.5" />
                            </button>
                            <button
                                type="button"
                                aria-label={`Move ${phase.name} later`}
                                disabled={index === phases.length - 1}
                                onClick={() => move(index, 1)}
                                className="shrink-0 rounded p-1 text-ink-subtle transition hover:text-ink disabled:opacity-30"
                            >
                                <ArrowDown className="size-3.5" />
                            </button>
                            <button
                                type="button"
                                aria-label={`Delete ${phase.name}`}
                                title="Delete the phase. Its issues are kept."
                                onClick={() =>
                                    router.delete(`/projects/${project.slug}/phases/${phase.id}`, {
                                        preserveScroll: true,
                                    })
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
                    <Field label="New phase" error={errors.name}>
                        <Input
                            value={data.name}
                            placeholder={phases.length === 0 ? 'Discovery' : 'Launch'}
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
 * What a client's own customers see.
 *
 * The person following a link about a broken checkout was using a shop. They should
 * see the shop — not the agency that built it, and not the tracker the agency
 * happens to use.
 */
/**
 * Per-project custom fields.
 *
 * The key is shown but never editable: it is what a saved view filters on and what
 * the CSV header says, so letting it drift behind a rename would break both quietly.
 */
function CustomFields({
    fields,
    types,
    project,
}: {
    fields: CustomFieldRow[];
    types: { value: string; label: string; has_options: boolean }[];
    project: ProjectSummary;
}) {
    const { data, setData, processing, errors, reset } = useForm({
        name: '',
        type: 'text',
        options: '',
        required: false,
        visible_to_client: false,
    });

    const needsOptions = types.find((t) => t.value === data.type)?.has_options ?? false;

    function submit(e: FormEvent) {
        e.preventDefault();

        router.post(
            `/projects/${project.slug}/fields`,
            {
                name: data.name,
                type: data.type,
                required: data.required,
                visible_to_client: data.visible_to_client,
                // Split here rather than server-side: the server takes a list, and a
                // textarea is the least annoying way for a person to type one.
                options: needsOptions
                    ? data.options
                          .split('\n')
                          .map((line) => line.trim())
                          .filter(Boolean)
                    : [],
            },
            { preserveScroll: true, onSuccess: () => reset() },
        );
    }

    return (
        <section className="mt-12 max-w-2xl">
            <h2 className="text-sm font-semibold text-ink">Custom fields</h2>
            <p className="mt-1 text-sm text-ink-muted">
                Extra fields on every issue in this project — a client reference, an
                environment, a browser. Filter on one with{' '}
                <code className="rounded bg-raised px-1 font-mono text-xs">
                    field:key=value
                </code>
                .
            </p>

            {fields.length > 0 && (
                <ul className="mt-4 divide-y divide-border rounded-xl border border-border bg-raised">
                    {fields.map((field) => (
                        <li key={field.id} className="flex items-center gap-3 px-4 py-3">
                            <div className="min-w-0 flex-1">
                                <span className="block truncate text-sm text-ink">
                                    {field.name}
                                    {field.required && (
                                        <span className="ml-1.5 text-xs text-ink-subtle">
                                            required
                                        </span>
                                    )}
                                </span>
                                <span className="font-mono text-xs text-ink-subtle">
                                    {field.key}
                                </span>
                            </div>

                            <span className="shrink-0 text-xs text-ink-subtle">
                                {types.find((t) => t.value === field.type)?.label ?? field.type}
                            </span>

                            <span
                                className={`shrink-0 rounded-full px-2 py-0.5 text-xs ${
                                    field.visible_to_client
                                        ? 'bg-accent-soft text-accent'
                                        : 'text-ink-subtle'
                                }`}
                            >
                                {field.visible_to_client ? 'Clients see it' : 'Internal'}
                            </span>

                            <button
                                type="button"
                                aria-label={`Delete ${field.name}`}
                                className="shrink-0 rounded p-1 text-ink-subtle transition hover:text-danger"
                                onClick={() => {
                                    // Said plainly: the values go too, and they do not
                                    // come back.
                                    if (
                                        !confirm(
                                            `Delete “${field.name}”? Every value recorded on an issue in this project goes with it, permanently.`,
                                        )
                                    ) {
                                        return;
                                    }

                                    router.delete(
                                        `/projects/${project.slug}/fields/${field.id}`,
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <Trash2 className="size-4" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <form onSubmit={submit} className="mt-4 space-y-3 rounded-xl border border-border p-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Name" error={errors.name}>
                        <Input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Client reference"
                        />
                    </Field>

                    <Field label="Type" error={errors.type}>
                        <select
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value)}
                            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink"
                        >
                            {types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>

                {needsOptions && (
                    <Field
                        label="Choices"
                        error={errors.options}
                        hint="One per line."
                    >
                        <Textarea
                            rows={4}
                            value={data.options}
                            onChange={(e) => setData('options', e.target.value)}
                            placeholder={'Production\nStaging\nLocal'}
                        />
                    </Field>
                )}

                <label className="flex items-center gap-2 text-sm text-ink-muted">
                    <input
                        type="checkbox"
                        checked={data.required}
                        onChange={(e) => setData('required', e.target.checked)}
                    />
                    Required when filing an issue
                </label>

                <label className="flex items-center gap-2 text-sm text-ink-muted">
                    <input
                        type="checkbox"
                        checked={data.visible_to_client}
                        onChange={(e) => setData('visible_to_client', e.target.checked)}
                    />
                    Clients can see it
                </label>

                <Button type="submit" size="sm" disabled={processing}>
                    <Plus className="size-3.5" />
                    Add field
                </Button>
            </form>
        </section>
    );
}

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
