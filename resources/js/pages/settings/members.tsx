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
    projects: number[];
    /** project id => tier, for clients only. */
    tiers?: Record<number, string>;
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
                        <MemberRow
                            key={member.id}
                            member={member}
                            projects={projects}
                            canManage={canManage}
                        />
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

/**
 * One member, and for a client the projects they can see.
 *
 * Grants could be given at invitation and never changed: adding one meant
 * re-inviting somebody who was already a member, and removing one meant editing the
 * database. A client staying on a project long after the work finished is the common
 * case, and it was the hard one.
 */
function MemberRow({
    member,
    projects,
    canManage,
}: {
    member: Member;
    projects: { id: number; name: string; key: string }[];
    canManage: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const [chosen, setChosen] = useState<number[]>(member.projects);
    // project id => tier. Seeded from what they already hold so opening the editor
    // and saving without touching anything changes nothing.
    const [tiers, setTiers] = useState<Record<number, string>>(member.tiers ?? {});

    const isClient = member.role === 'client';
    const names = projects
        .filter((project) => member.projects.includes(project.id))
        .map((project) => project.name);

    function save() {
        router.patch(
            `/settings/members/${member.id}/projects`,
            { project_ids: chosen, tiers },
            { preserveScroll: true, onSuccess: () => setEditing(false) },
        );
    }

    return (
        <li className="px-4 py-3">
            <div className="flex items-center gap-3">
                <Avatar name={member.name} size="md" />

                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm text-ink">
                        {member.name}
                        {member.is_you && (
                            <span className="ml-1.5 text-xs text-ink-subtle">you</span>
                        )}
                    </p>
                    <p className="truncate text-xs text-ink-subtle">{member.email}</p>

                    {isClient && !editing && (
                        <p className="mt-0.5 truncate text-[11px] text-ink-subtle">
                            Sees: {names.length > 0 ? names.join(', ') : 'nothing'}
                            {canManage && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setChosen(member.projects);
                                        setEditing(true);
                                    }}
                                    className="ml-1.5 text-accent hover:underline"
                                >
                                    Change
                                </button>
                            )}
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
                            router.delete(`/settings/members/${member.id}`, { preserveScroll: true })
                        }
                        className="rounded p-1.5 text-ink-subtle transition hover:text-danger"
                    >
                        <UserMinus className="size-4" />
                    </button>
                )}
            </div>

            {isClient && editing && (
                <div className="mt-3 rounded-lg border border-border bg-surface p-3">
                    <p className="text-xs text-ink-muted">
                        {member.name} sees only the projects ticked here, and only issues
                        marked visible to the client within them. <strong>Their own
                        issues</strong> means the ones they reported or were brought
                        into; <strong>all client issues</strong> is for a project
                        manager on the client side who needs the whole picture.
                    </p>

                    <div className="mt-2 grid gap-1.5 sm:grid-cols-2">
                        {projects.map((project) => (
                            <label
                                key={project.id}
                                className="flex items-center gap-2 text-sm text-ink-muted"
                            >
                                <input
                                    type="checkbox"
                                    checked={chosen.includes(project.id)}
                                    onChange={(e) =>
                                        setChosen((current) =>
                                            e.target.checked
                                                ? [...current, project.id]
                                                : current.filter((id) => id !== project.id),
                                        )
                                    }
                                />
                                <span className="min-w-0 flex-1 truncate">{project.name}</span>

                                {/* Only meaningful once the project is ticked, and
                                    hidden otherwise rather than shown disabled. */}
                                {chosen.includes(project.id) && (
                                    <select
                                        aria-label={`What ${member.name} sees on ${project.name}`}
                                        value={tiers[project.id] ?? 'client'}
                                        onChange={(e) =>
                                            setTiers((current) => ({
                                                ...current,
                                                [project.id]: e.target.value,
                                            }))
                                        }
                                        className="shrink-0 rounded border border-border bg-raised px-1.5 py-0.5 text-[11px] text-ink"
                                    >
                                        <option value="client">Their own issues</option>
                                        <option value="client_manager">All client issues</option>
                                    </select>
                                )}
                            </label>
                        ))}
                    </div>

                    {chosen.length === 0 && (
                        <p className="mt-2 text-xs text-amber-600 dark:text-amber-500">
                            A client needs at least one project. Remove them instead.
                        </p>
                    )}

                    <div className="mt-3 flex gap-2">
                        <Button size="sm" onClick={save} disabled={chosen.length === 0}>
                            Save
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setEditing(false)}>
                            Cancel
                        </Button>
                    </div>
                </div>
            )}
        </li>
    );
}
