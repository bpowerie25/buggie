import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AppLayout } from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';

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
}: {
    workspace: { name: string; slug: string; created_at: string };
    domain: string;
    can_delete: boolean;
    tokens?: TokenRow[];
    abilities?: string[];
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
