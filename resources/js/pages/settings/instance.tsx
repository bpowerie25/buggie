import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AppLayout } from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Send } from 'lucide-react';
import type { FormEvent } from 'react';

interface RegistrationSettings {
    mode: string;
    default: string;
    from_env: boolean;
    env_invalid: boolean;
    env_value: string | null;
    modes: { value: string; label: string; description: string }[];
}

interface MailSettings {
    mailer: string;
    host: string | null;
    port: number;
    username: string | null;
    encryption: string;
    from_address: string | null;
    from_name: string | null;
    has_password: boolean;
}

export default function InstanceSettings({
    mail,
    configured_by_env,
    registration,
}: {
    mail: MailSettings;
    configured_by_env: boolean;
    registration: RegistrationSettings;
}) {
    // The test-mail failure arrives as a shared error rather than a form one: it is
    // a different request, and the provider's own message is the useful part.
    const sendError = (usePage().props.errors as Record<string, string>)?.mail;

    const { data, setData, patch, processing, errors } = useForm({
        mailer: mail.mailer === 'smtp' ? 'smtp' : 'log',
        host: mail.host ?? '',
        port: mail.port || 587,
        username: mail.username ?? '',
        // Never populated from the server. Left blank, it keeps whatever is stored.
        password: '',
        encryption: mail.encryption ?? 'tls',
        from_address: mail.from_address ?? '',
        from_name: mail.from_name ?? 'Buggie',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        patch('/settings/instance', { preserveScroll: true });
    }

    return (
        <AppLayout title="Instance">
            <Head title="Instance settings" />

            <h2 className="text-xl font-semibold tracking-tight text-ink">Sending mail</h2>
            <p className="mt-1 max-w-2xl text-sm text-ink-muted">
                Invitations, password resets and every notification go through this. It
                applies to the whole install, not just this workspace.
            </p>

            {(data.mailer === 'log' || (data.mailer === 'smtp' && data.host.trim() === '')) && (
                <p className="mt-4 max-w-2xl rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-600 dark:text-amber-500">
                    {data.mailer === 'log'
                        ? 'Mail is going to the log, so nothing is being delivered. Nobody can be invited and nobody can reset a password until this is set up.'
                        : 'No SMTP server has been given, so nothing can be sent.'}
                </p>
            )}

            {configured_by_env && (
                <p className="mt-4 max-w-2xl text-xs text-ink-subtle">
                    Currently taken from the environment. Saving here overrides it, and the
                    environment stays as the fallback.
                </p>
            )}

            <form onSubmit={submit} className="mt-6 max-w-2xl space-y-4">
                <Field
                    label="Delivery"
                    error={errors.mailer}
                    hint="Log writes messages to the application log instead of sending them. Useful while setting up, useless afterwards."
                >
                    <select
                        value={data.mailer}
                        onChange={(e) => setData('mailer', e.target.value)}
                        className="w-full rounded-lg border border-border bg-raised px-3 py-2 text-sm text-ink"
                    >
                        <option value="smtp">SMTP</option>
                        <option value="log">Log only</option>
                    </select>
                </Field>

                {data.mailer === 'smtp' && (
                    <>
                        <div className="grid gap-4 sm:grid-cols-[1fr_8rem]">
                            <Field label="Host" error={errors.host}>
                                <Input
                                    value={data.host}
                                    placeholder="smtp.mailgun.org"
                                    onChange={(e) => setData('host', e.target.value)}
                                />
                            </Field>

                            <Field label="Port" error={errors.port}>
                                <Input
                                    type="number"
                                    value={data.port}
                                    onChange={(e) => setData('port', Number(e.target.value))}
                                />
                            </Field>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Username" error={errors.username}>
                                <Input
                                    value={data.username}
                                    autoComplete="off"
                                    onChange={(e) => setData('username', e.target.value)}
                                />
                            </Field>

                            <Field
                                label="Password"
                                error={errors.password}
                                hint={mail.has_password ? 'Stored. Leave blank to keep it.' : undefined}
                            >
                                <Input
                                    type="password"
                                    value={data.password}
                                    autoComplete="new-password"
                                    placeholder={mail.has_password ? '••••••••' : ''}
                                    onChange={(e) => setData('password', e.target.value)}
                                />
                            </Field>
                        </div>

                        <Field label="Encryption" error={errors.encryption}>
                            <select
                                value={data.encryption}
                                onChange={(e) => setData('encryption', e.target.value)}
                                className="w-full rounded-lg border border-border bg-raised px-3 py-2 text-sm text-ink"
                            >
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">None</option>
                            </select>
                        </Field>
                    </>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="From address"
                        error={errors.from_address}
                        hint="Must be a domain you are allowed to send as, or it will be treated as spam."
                    >
                        <Input
                            type="email"
                            value={data.from_address}
                            placeholder="buggie@example.com"
                            onChange={(e) => setData('from_address', e.target.value)}
                        />
                    </Field>

                    <Field label="From name" error={errors.from_name}>
                        <Input
                            value={data.from_name}
                            onChange={(e) => setData('from_name', e.target.value)}
                        />
                    </Field>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" disabled={processing}>
                        {processing ? 'Saving…' : 'Save'}
                    </Button>

                    {/*
                        The most useful control on the page. Mail failure is otherwise
                        silent — everything appears to work and nothing arrives.
                    */}
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() =>
                            router.post('/settings/instance/test-mail', {}, { preserveScroll: true })
                        }
                    >
                        <Send className="size-4" />
                        Send me a test
                    </Button>
                </div>

                {sendError && (
                    <p className="rounded-lg border border-danger/30 bg-danger-soft px-3 py-2 font-mono text-xs text-danger">
                        {sendError}
                    </p>
                )}
            </form>

            <RegistrationSection registration={registration} />
        </AppLayout>
    );
}

function RegistrationSection({ registration }: { registration: RegistrationSettings }) {
    const error = (usePage().props.errors as Record<string, string>)?.registration;
    const { data, setData, patch, processing } = useForm({ mode: registration.mode });

    function submit(e: FormEvent) {
        e.preventDefault();
        patch('/settings/instance/registration', { preserveScroll: true });
    }

    return (
        <section className="mt-12 max-w-2xl">
            <h2 className="text-xl font-semibold tracking-tight text-ink">Who can join</h2>
            <p className="mt-1 text-sm text-ink-muted">
                Whether strangers can make an account on this server, and who may create
                workspaces. Workspace invitations work in every mode.
            </p>

            {registration.from_env && (
                <p className="mt-4 rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink-muted">
                    Set on the server by{' '}
                    <code className="font-mono text-xs">
                        BUGGIE_REGISTRATION={registration.env_value}
                    </code>
                    , which overrides this screen.
                    {registration.env_invalid &&
                        ' That is not a mode Buggie knows, so it is treating the server as invitation-only.'}
                </p>
            )}

            <form onSubmit={submit} className="mt-6 space-y-2">
                {registration.modes.map((mode) => (
                    <label
                        key={mode.value}
                        className="flex cursor-pointer items-start gap-3 rounded-lg border border-border px-3 py-2.5 has-[:checked]:border-accent has-[:disabled]:cursor-default"
                    >
                        <input
                            type="radio"
                            name="registration"
                            value={mode.value}
                            checked={data.mode === mode.value}
                            disabled={registration.from_env}
                            onChange={() => setData('mode', mode.value)}
                            className="mt-1"
                        />
                        <span>
                            <span className="block text-sm font-medium text-ink">
                                {mode.label}
                                {mode.value === registration.default && (
                                    <span className="ml-2 text-xs font-normal text-ink-subtle">
                                        default here
                                    </span>
                                )}
                            </span>
                            <span className="block text-sm text-ink-muted">{mode.description}</span>
                        </span>
                    </label>
                ))}

                {error && <p className="text-sm text-danger">{error}</p>}

                {!registration.from_env && (
                    <Button type="submit" disabled={processing || data.mode === registration.mode}>
                        {processing ? 'Saving…' : 'Save'}
                    </Button>
                )}
            </form>
        </section>
    );
}
