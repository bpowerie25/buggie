import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AppLayout } from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface WebhookRow {
    id: number;
    name: string;
    url: string;
    project: string | null;
    events: string[];
    is_active: boolean;
    last_delivered_at: string | null;
    deliveries: {
        event: string;
        status: number | null;
        error: string | null;
        ok: boolean;
        at: string | null;
    }[];
}

interface ChatRow {
    id: number;
    name: string;
    provider: string;
    provider_label: string;
    project: string | null;
    events: string[];
    is_active: boolean;
    internal_activity: boolean;
    last_delivered_at: string | null;
    // Whether an address is stored, never the address. It is the whole credential.
    has_url: boolean;
    deliveries: {
        event: string;
        status: number | null;
        error: string | null;
        ok: boolean;
        at: string | null;
    }[];
}

interface ChatProvider {
    value: string;
    label: string;
    hint: string;
}

interface TokenRow {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string;
}

export default function WorkspaceSettings({
    workspace,
    domain,
    can_delete,
    tokens = [],
    abilities = [],
    webhooks = [],
    webhookEvents = [],
    chatIntegrations = [],
    chatProviders = [],
    projects = [],
}: {
    workspace: { name: string; slug: string; created_at: string };
    domain: string;
    can_delete: boolean;
    tokens?: TokenRow[];
    abilities?: string[];
    webhooks?: WebhookRow[];
    webhookEvents?: { value: string; label: string }[];
    chatIntegrations?: ChatRow[];
    chatProviders?: ChatProvider[];
    projects?: { id: number; name: string }[];
}) {
    const { data, setData, patch, processing, errors } = useForm({ name: workspace.name });
    const [confirm, setConfirm] = useState('');

    function submit(e: FormEvent) {
        e.preventDefault();
        patch('/settings/workspace', { preserveScroll: true });
    }

    return (
        <AppLayout title="Workspace">
            <Head title="Workspace settings" />

            <form onSubmit={submit} className="max-w-lg space-y-4">
                <Field label="Workspace name" error={errors.name}>
                    <Input
                        value={data.name}
                        required
                        onChange={(e) => setData('name', e.target.value)}
                    />
                </Field>

                <Field
                    label="Address"
                    hint="Fixed after creation — it appears in every widget snippet, invitation link and bookmark."
                >
                    <Input
                        value={`${workspace.slug}.${domain}`}
                        disabled
                        className="font-mono"
                    />
                </Field>

                <Button type="submit" disabled={processing}>
                    {processing ? 'Saving…' : 'Save changes'}
                </Button>
            </form>

            <ApiTokens tokens={tokens} abilities={abilities} workspace={workspace} domain={domain} />

            <Webhooks webhooks={webhooks} events={webhookEvents} projects={projects} />

            <ChatIntegrations
                integrations={chatIntegrations}
                providers={chatProviders}
                events={webhookEvents}
                projects={projects}
            />

            {can_delete && (
                <section className="mt-12 max-w-lg rounded-xl border border-danger/30 bg-danger-soft p-4">
                    <h2 className="text-sm font-semibold text-ink">Delete this workspace</h2>
                    <p className="mt-1 text-sm text-ink-muted">
                        Removes every project, issue and report, and cancels any subscription.
                        Type <span className="font-mono text-ink">{workspace.slug}</span> to
                        confirm.
                    </p>

                    <div className="mt-3 flex gap-2">
                        <Input
                            value={confirm}
                            placeholder={workspace.slug}
                            aria-label="Type the workspace address to confirm"
                            className="font-mono"
                            onChange={(e) => setConfirm(e.target.value)}
                        />
                        <Button
                            variant="danger"
                            disabled={confirm !== workspace.slug}
                            onClick={() =>
                                router.delete('/settings/workspace', { data: { confirm } })
                            }
                        >
                            Delete
                        </Button>
                    </div>
                </section>
            )}
        </AppLayout>
    );
}

/**
 * Personal access tokens for the API.
 *
 * A token is shown exactly once, on the response that creates it. Storing it in a
 * form anyone could read back would make hashing it pointless, and a token you can
 * retrieve later is a password written on the wall.
 */
function ApiTokens({
    tokens,
    abilities,
    workspace,
    domain,
}: {
    tokens: TokenRow[];
    abilities: string[];
    workspace: { slug: string };
    domain: string;
}) {
    const { props } = usePage<{ flash?: { token?: string } }>();
    const created = props.flash?.token;
    const [copied, setCopied] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        abilities: ['read'] as string[],
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/settings/tokens', { preserveScroll: true, onSuccess: () => reset('name') });
    }

    return (
        <section className="mt-12 max-w-2xl">
            <h2 className="text-sm font-semibold text-ink">API tokens</h2>
            <p className="mt-1 text-sm text-ink-muted">
                For scripting against this workspace. A token works here and nowhere else,
                even in another workspace you belong to.
            </p>

            {created && (
                <div className="mt-4 rounded-xl border border-accent/30 bg-accent-soft p-4">
                    <p className="text-sm font-medium text-ink">
                        Copy this now — it is not shown again.
                    </p>
                    <div className="mt-2 flex items-start gap-2">
                        <pre className="min-w-0 flex-1 overflow-x-auto rounded-lg bg-surface p-2.5 font-mono text-[11px] text-ink-muted">
                            {created}
                        </pre>
                        <button
                            type="button"
                            aria-label="Copy token"
                            onClick={() => {
                                navigator.clipboard?.writeText(created);
                                setCopied(true);
                                setTimeout(() => setCopied(false), 1500);
                            }}
                            className="rounded-lg border border-border p-2 text-ink-subtle transition hover:text-ink"
                        >
                            {copied ? <Check className="size-3.5 text-success" /> : <Copy className="size-3.5" />}
                        </button>
                    </div>
                    <pre className="mt-3 overflow-x-auto rounded-lg bg-surface p-2.5 font-mono text-[11px] text-ink-subtle">
{`curl -H "Authorization: Bearer <token>" \\
  https://${workspace.slug}.${domain}/api/v1/issues`}
                    </pre>
                </div>
            )}

            <form onSubmit={submit} className="mt-4 flex flex-wrap items-end gap-3">
                <div className="flex-1">
                    <Field label="Name" error={errors.name}>
                        <Input
                            value={data.name}
                            required
                            placeholder="Deploy script"
                            onChange={(e) => setData('name', e.target.value)}
                        />
                    </Field>
                </div>

                <div className="flex items-center gap-3 pb-2">
                    {abilities.map((ability) => (
                        <label key={ability} className="flex items-center gap-1.5 text-sm text-ink-muted">
                            <input
                                type="checkbox"
                                checked={data.abilities.includes(ability)}
                                onChange={(e) =>
                                    setData(
                                        'abilities',
                                        e.target.checked
                                            ? [...data.abilities, ability]
                                            : data.abilities.filter((a) => a !== ability),
                                    )
                                }
                            />
                            {ability}
                        </label>
                    ))}
                </div>

                <Button type="submit" size="sm" disabled={processing || data.abilities.length === 0}>
                    Create token
                </Button>
            </form>

            {tokens.length > 0 && (
                <ul className="mt-4 divide-y divide-border rounded-xl border border-border">
                    {tokens.map((token) => (
                        <li key={token.id} className="flex items-center gap-3 px-4 py-3">
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm text-ink">{token.name}</span>
                                <span className="text-xs text-ink-subtle">
                                    {token.abilities.join(', ')} ·{' '}
                                    {token.last_used_at
                                        ? `last used ${new Date(token.last_used_at).toLocaleDateString()}`
                                        : 'never used'}
                                    {token.expires_at ? ` · expires ${token.expires_at}` : ''}
                                </span>
                            </span>
                            <button
                                type="button"
                                aria-label={`Revoke ${token.name}`}
                                onClick={() =>
                                    router.delete(`/settings/tokens/${token.id}`, { preserveScroll: true })
                                }
                                className="rounded p-1 text-ink-subtle transition hover:text-danger"
                            >
                                <Trash2 className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * Webhooks, and whether they are working.
 *
 * The delivery list is the point. "It isn't working" with nothing to look at is the
 * usual experience of webhooks, and the status of the last few calls answers it.
 */
function Webhooks({
    webhooks,
    events,
    projects,
}: {
    webhooks: WebhookRow[];
    events: { value: string; label: string }[];
    projects: { id: number; name: string }[];
}) {
    const [adding, setAdding] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        url: '',
        project_id: '' as string | number,
        events: [] as string[],
    });

    function submit(e: FormEvent) {
        e.preventDefault();

        post('/settings/webhooks', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setAdding(false);
            },
        });
    }

    return (
        <section className="mt-12 max-w-2xl">
            <h2 className="text-sm font-semibold text-ink">Webhooks</h2>
            <p className="mt-1 text-sm text-ink-muted">
                Call a URL when something happens — Slack, Teams, or anything of your
                own. Every delivery is signed so the receiver can tell it came from here.
            </p>

            {webhooks.length > 0 && (
                <ul className="mt-4 space-y-3">
                    {webhooks.map((webhook) => (
                        <li
                            key={webhook.id}
                            className="rounded-xl border border-border bg-raised p-4"
                        >
                            <div className="flex items-start gap-3">
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm text-ink">
                                        {webhook.name}
                                        {!webhook.is_active && (
                                            <span className="ml-1.5 text-xs text-ink-subtle">
                                                disabled
                                            </span>
                                        )}
                                    </span>
                                    <span className="block truncate font-mono text-[11px] text-ink-subtle">
                                        {webhook.url}
                                    </span>
                                    <span className="text-xs text-ink-subtle">
                                        {webhook.project ?? 'Every project'} ·{' '}
                                        {webhook.events.length} event
                                        {webhook.events.length === 1 ? '' : 's'}
                                    </span>
                                </span>

                                <button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            `/settings/webhooks/${webhook.id}/test`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="shrink-0 rounded-md border border-border px-2 py-1 text-xs text-ink-muted transition hover:text-ink"
                                >
                                    Test
                                </button>

                                <button
                                    type="button"
                                    aria-label={`Delete ${webhook.name}`}
                                    onClick={() =>
                                        router.delete(`/settings/webhooks/${webhook.id}`, {
                                            preserveScroll: true,
                                        })
                                    }
                                    className="shrink-0 rounded p-1 text-ink-subtle transition hover:text-danger"
                                >
                                    <Trash2 className="size-3.5" />
                                </button>
                            </div>

                            {webhook.deliveries.length > 0 && (
                                <ul className="mt-3 space-y-0.5 border-t border-border pt-2">
                                    {webhook.deliveries.map((delivery, i) => (
                                        <li
                                            key={i}
                                            className="flex items-center gap-2 font-mono text-[11px]"
                                        >
                                            <span
                                                className={
                                                    delivery.ok ? 'text-success' : 'text-danger'
                                                }
                                            >
                                                {delivery.status ?? 'failed'}
                                            </span>
                                            <span className="text-ink-subtle">{delivery.event}</span>
                                            {delivery.error && (
                                                <span className="min-w-0 truncate text-ink-subtle">
                                                    {delivery.error}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {adding ? (
                <form onSubmit={submit} className="mt-4 space-y-3 rounded-xl border border-border p-4">
                    <Field label="Name" error={errors.name}>
                        <Input
                            value={data.name}
                            placeholder="Slack — #bugs"
                            onChange={(e) => setData('name', e.target.value)}
                        />
                    </Field>

                    <Field
                        label="URL"
                        error={errors.url}
                        hint="Must be a public address. Private and internal networks are refused."
                    >
                        <Input
                            value={data.url}
                            placeholder="https://hooks.slack.com/services/..."
                            onChange={(e) => setData('url', e.target.value)}
                        />
                    </Field>

                    <Field label="Project" error={errors.project_id}>
                        <select
                            value={data.project_id}
                            onChange={(e) => setData('project_id', e.target.value)}
                            className="w-full rounded-lg border border-border bg-raised px-3 py-2 text-sm text-ink"
                        >
                            <option value="">Every project</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <div>
                        <p className="text-sm text-ink">Send when</p>
                        {errors.events && (
                            <p className="text-xs text-danger">{errors.events}</p>
                        )}
                        <div className="mt-1 space-y-1">
                            {events.map((event) => (
                                <label
                                    key={event.value}
                                    className="flex items-center gap-2 text-sm text-ink-muted"
                                >
                                    <input
                                        type="checkbox"
                                        checked={data.events.includes(event.value)}
                                        onChange={(e) =>
                                            setData(
                                                'events',
                                                e.target.checked
                                                    ? [...data.events, event.value]
                                                    : data.events.filter((v) => v !== event.value),
                                            )
                                        }
                                    />
                                    {event.label}
                                </label>
                            ))}
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            disabled={processing || data.events.length === 0}
                        >
                            Create
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setAdding(false)}
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            ) : (
                <Button size="sm" variant="ghost" className="mt-4" onClick={() => setAdding(true)}>
                    Add a webhook
                </Button>
            )}
        </section>
    );
}

/**
 * Slack and Teams channels.
 *
 * The address is write-only: it is never sent to this page, so an existing channel
 * shows its name and nothing else. Re-pasting replaces it; leaving the field blank
 * leaves it alone, which is the same bargain the mail settings screen offers for the
 * SMTP password.
 */
function ChatIntegrations({
    integrations,
    providers,
    events,
    projects,
}: {
    integrations: ChatRow[];
    providers: ChatProvider[];
    events: { value: string; label: string }[];
    projects: { id: number; name: string }[];
}) {
    const [adding, setAdding] = useState(false);

    // The test failure arrives as a shared error rather than a form one: it is a
    // different request, and the provider's own words are the useful part.
    const sendError = (usePage().props.errors as Record<string, string>)?.chat;

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        provider: providers[0]?.value ?? 'slack',
        url: '',
        project_id: '' as string | number,
        events: [] as string[],
        internal_activity: false,
    });

    function submit(e: FormEvent) {
        e.preventDefault();

        post('/settings/chat', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setAdding(false);
            },
        });
    }

    const hint = providers.find((p) => p.value === data.provider)?.hint;

    return (
        <section className="mt-12 max-w-2xl">
            <h2 className="text-sm font-semibold text-ink">Slack and Teams</h2>
            <p className="mt-1 text-sm text-ink-muted">
                Post a readable message into a channel when something happens. Paste the
                incoming-webhook address the service gives you — Buggie stores it
                encrypted and never shows it again.
            </p>

            {sendError && (
                <p className="mt-3 rounded-lg border border-danger/30 bg-danger-soft px-3 py-2 font-mono text-xs text-danger">
                    {sendError}
                </p>
            )}

            {integrations.length > 0 && (
                <ul className="mt-4 space-y-3">
                    {integrations.map((integration) => (
                        <li
                            key={integration.id}
                            className="rounded-xl border border-border bg-raised p-4"
                        >
                            <div className="flex items-start gap-3">
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm text-ink">
                                        {integration.name}
                                        {!integration.is_active && (
                                            <span className="ml-1.5 text-xs text-ink-subtle">
                                                disabled
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-xs text-ink-subtle">
                                        {integration.provider_label} ·{' '}
                                        {integration.project ?? 'Every project'} ·{' '}
                                        {integration.events.length} event
                                        {integration.events.length === 1 ? '' : 's'}
                                        {integration.internal_activity && ' · internal notes'}
                                    </span>
                                    {!integration.has_url && (
                                        <span className="block text-xs text-danger">
                                            The stored address cannot be read. Re-enter it.
                                        </span>
                                    )}
                                </span>

                                <button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            `/settings/chat/${integration.id}/test`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="shrink-0 rounded-md border border-border px-2 py-1 text-xs text-ink-muted transition hover:text-ink"
                                >
                                    Test
                                </button>

                                <button
                                    type="button"
                                    aria-label={`Delete ${integration.name}`}
                                    onClick={() =>
                                        router.delete(`/settings/chat/${integration.id}`, {
                                            preserveScroll: true,
                                        })
                                    }
                                    className="shrink-0 rounded p-1 text-ink-subtle transition hover:text-danger"
                                >
                                    <Trash2 className="size-3.5" />
                                </button>
                            </div>

                            {integration.deliveries.length > 0 && (
                                <ul className="mt-3 space-y-0.5 border-t border-border pt-2">
                                    {integration.deliveries.map((delivery, i) => (
                                        <li
                                            key={i}
                                            className="flex items-center gap-2 font-mono text-[11px]"
                                        >
                                            <span
                                                className={
                                                    delivery.ok ? 'text-success' : 'text-danger'
                                                }
                                            >
                                                {delivery.status ?? 'failed'}
                                            </span>
                                            <span className="text-ink-subtle">{delivery.event}</span>
                                            {delivery.error && (
                                                <span className="min-w-0 truncate text-ink-subtle">
                                                    {delivery.error}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {adding ? (
                <form onSubmit={submit} className="mt-4 space-y-3 rounded-xl border border-border p-4">
                    <Field label="Service" error={errors.provider}>
                        <select
                            value={data.provider}
                            onChange={(e) => setData('provider', e.target.value)}
                            className="w-full rounded-lg border border-border bg-raised px-3 py-2 text-sm text-ink"
                        >
                            {providers.map((provider) => (
                                <option key={provider.value} value={provider.value}>
                                    {provider.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Name" error={errors.name}>
                        <Input
                            value={data.name}
                            placeholder="#bugs"
                            onChange={(e) => setData('name', e.target.value)}
                        />
                    </Field>

                    <Field label="Address" error={errors.url} hint={hint}>
                        <Input
                            value={data.url}
                            placeholder="https://hooks.slack.com/services/..."
                            onChange={(e) => setData('url', e.target.value)}
                        />
                    </Field>

                    <Field label="Project" error={errors.project_id}>
                        <select
                            value={data.project_id}
                            onChange={(e) => setData('project_id', e.target.value)}
                            className="w-full rounded-lg border border-border bg-raised px-3 py-2 text-sm text-ink"
                        >
                            <option value="">Every project</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <div>
                        <p className="text-sm text-ink">Post when</p>
                        {errors.events && <p className="text-xs text-danger">{errors.events}</p>}
                        <div className="mt-1 space-y-1">
                            {events.map((event) => (
                                <label
                                    key={event.value}
                                    className="flex items-center gap-2 text-sm text-ink-muted"
                                >
                                    <input
                                        type="checkbox"
                                        checked={data.events.includes(event.value)}
                                        onChange={(e) =>
                                            setData(
                                                'events',
                                                e.target.checked
                                                    ? [...data.events, event.value]
                                                    : data.events.filter((v) => v !== event.value),
                                            )
                                        }
                                    />
                                    {event.label}
                                </label>
                            ))}
                        </div>
                    </div>

                    <label className="flex items-start gap-2 text-sm text-ink-muted">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={data.internal_activity}
                            onChange={(e) => setData('internal_activity', e.target.checked)}
                        />
                        <span>
                            This channel is the team&rsquo;s own
                            <span className="block text-xs text-ink-subtle">
                                Announce internal notes here too. Their text is never sent
                                either way — only that somebody left one.
                            </span>
                        </span>
                    </label>

                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            disabled={processing || data.events.length === 0}
                        >
                            Add
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setAdding(false)}
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            ) : (
                <Button size="sm" variant="ghost" className="mt-4" onClick={() => setAdding(true)}>
                    Add a channel
                </Button>
            )}
        </section>
    );
}
