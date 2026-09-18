import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { Avatar } from '@/components/issue-bits';
import { AppLayout } from '@/layouts/app-layout';
import type { SharedProps } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Trash2, UserMinus } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
    is_owner: boolean;
    is_you: boolean;
    projects: string[];
}

interface PendingInvitation {
    id: number;
    email: string;
    role: string;
    expires_at: string;
    url: string;
}

export default function Members({
    members,
    invitations,
    projects,
    roles,
}: {
    members: Member[];
    invitations: PendingInvitation[];
    projects: { id: number; name: string; key: string }[];
    roles: { value: string; label: string }[];
}) {
    const { auth } = usePage<SharedProps>().props;
    const canManage = auth.role === 'owner' || auth.role === 'admin';

    const { data, setData, post, processing, errors, reset } = useForm<{
        email: string;
        role: string;
        project_ids: number[];
    }>({ email: '', role: 'member', project_ids: [] });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/settings/members', { preserveScroll: true, onSuccess: () => reset() });
    }

    return (
        <AppLayout title="Members">
            <Head title="Members" />

            {canManage && (
                <section className="max-w-2xl">
                    <h2 className="text-sm font-semibold text-ink">Invite someone</h2>
                    <p className="mt-1 text-sm text-pretty text-ink-muted">
                        Staff see every project. A client sees only the projects you choose,
                        and only the issues marked visible to them.
                    </p>

                    <form onSubmit={submit} className="mt-4 space-y-3">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="min-w-56 flex-1">
                                <Field label="Email" error={errors.email}>
                                    <Input
                                        type="email"
                                        value={data.email}
                                        required
                                        placeholder="person@example.com"
                                        onChange={(e) => setData('email', e.target.value)}
                                    />
                                </Field>
                            </div>

                            <Field label="Role">
                                <select
                                    value={data.role}
                                    onChange={(e) => setData('role', e.target.value)}
                                    className="h-[38px] rounded-lg border border-border-strong bg-raised px-2 text-sm text-ink focus:border-accent focus:outline-none"
                                >
                                    {roles.map((role) => (
                                        <option key={role.value} value={role.value}>
                                            {role.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Button type="submit" disabled={processing || !data.email}>
                                Send invitation
                            </Button>
                        </div>

                        {data.role === 'client' && (
                            <fieldset>
                                <legend className="mb-1.5 text-sm font-medium text-ink">
                                    Projects this client can see
                                </legend>
                                <div className="flex flex-wrap gap-1.5">
                                    {projects.map((project) => {
                                        const on = data.project_ids.includes(project.id);

                                        return (
                                            <button
                                                key={project.id}
                                                type="button"
                                                aria-pressed={on}
                                                onClick={() =>
                                                    setData(
                                                        'project_ids',
                                                        on
                                                            ? data.project_ids.filter((id) => id !== project.id)
                                                            : [...data.project_ids, project.id],
                                                    )
                                                }
                                                className={`rounded-lg border px-2 py-1 text-xs transition ${
                                                    on
                                                        ? 'border-accent bg-accent-soft text-accent'
                                                        : 'border-border text-ink-muted hover:text-ink'
                                                }`}
                                            >
                                                {project.name}
                                            </button>
                                        );
                                    })}
                                </div>
                                {errors.project_ids && (
                                    <p className="mt-1 text-xs text-danger">{errors.project_ids}</p>
                                )}
                            </fieldset>
                        )}
                    </form>
                </section>
            )}

            {invitations.length > 0 && (
                <section className="mt-10 max-w-2xl">
                    <h2 className="text-sm font-semibold text-ink">Pending invitations</h2>
                    <ul className="mt-3 divide-y divide-border overflow-hidden rounded-xl border border-border bg-raised">
                        {invitations.map((invitation) => (
                            <InvitationRow key={invitation.id} invitation={invitation} canManage={canManage} />
                        ))}
                    </ul>
                </section>
            )}

            <section className="mt-10 max-w-2xl">
                <h2 className="text-sm font-semibold text-ink">
                    Members ({members.length})
                </h2>

                <ul className="mt-3 divide-y divide-border overflow-hidden rounded-xl border border-border bg-raised">
                    {members.map((member) => (
                        <li key={member.id} className="flex items-center gap-3 px-4 py-3">
                            <Avatar name={member.name} size="md" />

                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm text-ink">
                                    {member.name}
                                    {member.is_you && (
                                        <span className="ml-1.5 text-xs text-ink-subtle">you</span>
                                    )}
                                </p>
                                <p className="truncate text-xs text-ink-subtle">{member.email}</p>
                                {member.projects.length > 0 && (
                                    <p className="mt-0.5 truncate text-[11px] text-ink-subtle">
                                        Sees: {member.projects.join(', ')}
                                    </p>
                                )}
                            </div>

                            <span className="shrink-0 rounded bg-surface px-1.5 py-0.5 text-[11px] text-ink-muted capitalize">
                                {member.is_owner ? 'owner' : member.role}
                            </span>

                            {canManage && !member.is_owner && (
                                <button
                                    type="button"
                                    aria-label={`Remove ${member.name}`}
                                    onClick={() =>
                                        router.delete(`/settings/members/${member.id}`, {
                                            preserveScroll: true,
                                        })
                                    }
                                    className="rounded p-1.5 text-ink-subtle transition hover:text-danger"
                                >
                                    <UserMinus className="size-4" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            </section>
        </AppLayout>
    );
}

function InvitationRow({
    invitation,
    canManage,
}: {
    invitation: PendingInvitation;
    canManage: boolean;
}) {
    const [copied, setCopied] = useState(false);

    return (
        <li className="flex items-center gap-3 px-4 py-2.5">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm text-ink">{invitation.email}</p>
                <p className="text-xs text-ink-subtle">
                    Invited as {invitation.role} · expires{' '}
                    {new Date(invitation.expires_at).toLocaleDateString()}
                </p>
            </div>

            <button
                type="button"
                aria-label="Copy invitation link"
                title="Copy invitation link"
                onClick={() => {
                    navigator.clipboard?.writeText(invitation.url);
                    setCopied(true);
                    setTimeout(() => setCopied(false), 1500);
                }}
                className="rounded p-1.5 text-ink-subtle transition hover:text-ink"
            >
                {copied ? <Check className="size-4 text-success" /> : <Copy className="size-4" />}
            </button>

            {canManage && (
                <button
                    type="button"
                    aria-label={`Revoke invitation for ${invitation.email}`}
                    onClick={() =>
                        router.delete(`/settings/invitations/${invitation.id}`, {
                            preserveScroll: true,
                        })
                    }
                    className="rounded p-1.5 text-ink-subtle transition hover:text-danger"
                >
                    <Trash2 className="size-4" />
                </button>
            )}
        </li>
    );
}
