import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { Avatar, relativeTime } from '@/components/issue-bits';
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
    /** For the workload screen; staff only. */
    weekly_hours?: number | null;
    discipline?: string | null;
}

/** A client's access to one project, in the words the screen uses for it. */
const TIERS = [
    { value: 'client', label: 'Own issues only' },
    { value: 'client_manager', label: 'All client-visible issues' },
];

interface MemberEvent {
    id: number;
    actor: string | null;
    subject: string | null;
    project: string | null;
    from: string | null;
    to: string | null;
    created_at: string;
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
    memberEvents = [],
    disciplines = [],
}: {
    members: Member[];
    invitations: PendingInvitation[];
    projects: { id: number; name: string; key: string }[];
    roles: { value: string; label: string }[];
    /** Recent changes to what clients can see. Empty for those who cannot change it. */
    memberEvents?: MemberEvent[];
    /** Suggestions for the discipline field: ones already used, then common ones. */
    disciplines?: string[];
}) {
    const { auth } = usePage<SharedProps>().props;
    const canManage = auth.role === 'owner' || auth.role === 'admin';

    const { data, setData, post, processing, errors, reset } = useForm<{
        email: string;
        role: string;
        project_ids: number[];
        /** project id => tier; a project left out is "own issues only". */
        tiers: Record<number, string>;
    }>({ email: '', role: 'member', project_ids: [], tiers: {} });

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

                                {data.project_ids.length > 0 && (
                                    <div className="mt-2 space-y-1">
                                        {projects
                                            .filter((project) => data.project_ids.includes(project.id))
                                            .map((project) => (
                                                <label
                                                    key={project.id}
                                                    className="flex items-center gap-2 text-xs text-ink-muted"
                                                >
                                                    <span className="min-w-0 flex-1 truncate">{project.name}</span>
                                                    <TierSelect
                                                        label={`What they see on ${project.name}`}
                                                        value={data.tiers[project.id] ?? 'client'}
                                                        onChange={(tier) =>
                                                            setData('tiers', { ...data.tiers, [project.id]: tier })
                                                        }
                                                    />
                                                </label>
                                            ))}
                                    </div>
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
                            disciplines={disciplines}
                        />
                    ))}
                </ul>
            </section>

            {canManage && <Disciplines names={disciplines} />}

            {memberEvents.length > 0 && (
                <section className="mt-10 max-w-2xl">
                    <h2 className="text-sm font-semibold text-ink">Recent changes</h2>
                    <ul className="mt-3 space-y-1.5 text-xs text-ink-muted">
                        {memberEvents.map((event) => (
                            <li key={event.id}>
                                {event.actor ?? 'Someone'} changed {event.subject ?? 'a client'} on{' '}
                                {event.project ?? 'a deleted project'} from {event.from} to {event.to}
                                <span className="text-ink-subtle"> · {relativeTime(event.created_at)}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
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
 * The workspace's disciplines, in the order the workload screen groups people by.
 * Renaming one renames it on everybody who has it; removing one leaves them with none.
 */
function Disciplines({ names }: { names: string[] }) {
    const [adding, setAdding] = useState('');
    const [error, setError] = useState<string>();

    const opts = { preserveScroll: true, onError: (e: Record<string, string>) => setError(e.name ?? e.to ?? e.names) };

    function move(index: number, by: -1 | 1) {
        const next = [...names];
        [next[index], next[index + by]] = [next[index + by], next[index]];
        router.put('/settings/disciplines/order', { names: next }, opts);
    }

    return (
        <section className="mt-10 max-w-xl">
            <h2 className="text-sm font-semibold text-ink">Disciplines</h2>
            <p className="mt-1 text-sm text-ink-muted">
                What each member of staff does. The Workload screen groups people by these, in this order.
            </p>

            <ul className="mt-3 divide-y divide-border rounded-xl border border-border bg-raised">
                {names.map((name, index) => (
                    <li key={name} className="flex items-center gap-2 px-3 py-1.5">
                        <input
                            defaultValue={name}
                            maxLength={40}
                            aria-label={`Rename ${name}`}
                            onBlur={(e) => {
                                const to = e.currentTarget.value.trim();
                                if (!to) e.currentTarget.value = name;
                                else if (to !== name) {
                                    setError(undefined);
                                    router.patch('/settings/disciplines', { from: name, to }, opts);
                                }
                            }}
                            onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
                            className="min-w-0 flex-1 rounded-md border border-transparent bg-transparent px-1.5 py-1 text-sm text-ink hover:border-border focus:border-border focus:outline-none"
                        />
                        <button
                            type="button"
                            aria-label={`Move ${name} up`}
                            disabled={index === 0}
                            onClick={() => move(index, -1)}
                            className="rounded p-1 text-xs text-ink-subtle hover:text-ink disabled:opacity-30"
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            aria-label={`Move ${name} down`}
                            disabled={index === names.length - 1}
                            onClick={() => move(index, 1)}
                            className="rounded p-1 text-xs text-ink-subtle hover:text-ink disabled:opacity-30"
                        >
                            ↓
                        </button>
                        <button
                            type="button"
                            aria-label={`Remove ${name}`}
                            title="Remove it. Anybody who has it is left with no discipline."
                            onClick={() => router.delete('/settings/disciplines', { data: { name }, ...opts })}
                            className="rounded p-1 text-xs text-ink-subtle hover:text-danger"
                        >
                            ✕
                        </button>
                    </li>
                ))}
            </ul>

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    if (!adding.trim()) return;
                    setError(undefined);
                    router.post('/settings/disciplines', { name: adding.trim() }, { ...opts, onSuccess: () => setAdding('') });
                }}
                className="mt-2 flex gap-2"
            >
                <input
                    value={adding}
                    maxLength={40}
                    placeholder="New discipline, e.g. Copywriter"
                    aria-label="New discipline"
                    onChange={(e) => setAdding(e.target.value)}
                    className="flex-1 rounded-lg border border-border bg-surface px-2.5 py-1.5 text-sm text-ink"
                />
                <button
                    type="submit"
                    disabled={!adding.trim()}
                    className="rounded-lg bg-accent px-3 py-1.5 text-sm text-accent-ink disabled:opacity-50"
                >
                    Add
                </button>
            </form>
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </section>
    );
}

/**
 * A member of staff's weekly hours and what they do, for the workload screen. Saved
 * when a field is left, like the issue sidebar. Blank hours means "not set", which the
 * workload screen shows without a limit rather than against a guess.
 */
function Capacity({
    member,
    canManage,
    disciplines,
}: {
    member: Member;
    canManage: boolean;
    disciplines: string[];
}) {
    const [hours, setHours] = useState(member.weekly_hours == null ? '' : String(member.weekly_hours));
    const [discipline, setDiscipline] = useState(member.discipline ?? '');

    function save(chosen = discipline) {
        const next = { weekly_hours: hours === '' ? null : Number(hours), discipline: chosen || null };

        if (next.weekly_hours === (member.weekly_hours ?? null) && next.discipline === (member.discipline ?? null)) return;

        router.patch(`/settings/members/${member.id}/capacity`, next, { preserveScroll: true });
    }

    if (!canManage) {
        return member.weekly_hours != null || member.discipline ? (
            <p className="mt-0.5 text-[11px] text-ink-subtle">
                {[member.discipline, member.weekly_hours != null ? `${member.weekly_hours}h a week` : null]
                    .filter(Boolean)
                    .join(' · ')}
            </p>
        ) : null;
    }

    const field =
        'rounded-md border border-transparent bg-transparent px-1.5 py-0.5 text-[11px] text-ink-muted transition hover:border-border focus:border-border focus:outline-none';

    return (
        <div className="mt-0.5 flex flex-wrap items-center gap-1">
            <select
                value={discipline}
                aria-label={`What ${member.name} does`}
                onChange={(e) => {
                    setDiscipline(e.target.value);
                    save(e.target.value);
                }}
                className={`w-36 ${field}`}
            >
                <option value="">No discipline</option>
                {disciplines.map((d) => (
                    <option key={d} value={d}>
                        {d}
                    </option>
                ))}
            </select>
            <input
                value={hours}
                type="number"
                min={0}
                max={168}
                step={0.5}
                placeholder="Hours a week"
                aria-label={`${member.name}'s hours a week`}
                onChange={(e) => setHours(e.target.value)}
                onBlur={() => save()}
                className={`w-28 ${field}`}
            />
        </div>
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
    disciplines,
}: {
    member: Member;
    projects: { id: number; name: string; key: string }[];
    canManage: boolean;
    disciplines: string[];
}) {
    const [editing, setEditing] = useState(false);
    const [chosen, setChosen] = useState<number[]>(member.projects);
    // project id => tier. Seeded from what they already hold so opening the editor
    // and saving without touching anything changes nothing.
    const [tiers, setTiers] = useState<Record<number, string>>(member.tiers ?? {});

    const isClient = member.role === 'client';
    const granted = projects.filter((project) => member.projects.includes(project.id));

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

                    {!isClient && <Capacity member={member} canManage={canManage} disciplines={disciplines} />}

                    {isClient && !editing && (
                        <div className="mt-1 space-y-0.5">
                            {granted.length === 0 && (
                                <p className="text-[11px] text-ink-subtle">Sees: nothing</p>
                            )}

                            {/* One row per project, with what they see on it. Changing the
                                tier saves at once, like the issue sidebar. */}
                            {granted.map((project) => (
                                <div key={project.id} className="flex items-center gap-2 text-[11px] text-ink-subtle">
                                    <span className="min-w-0 truncate">{project.name}</span>
                                    {canManage ? (
                                        <TierSelect
                                            label={`What ${member.name} sees on ${project.name}`}
                                            value={member.tiers?.[project.id] ?? 'client'}
                                            onChange={(tier) =>
                                                router.patch(
                                                    `/settings/members/${member.id}/projects/${project.id}/tier`,
                                                    { tier },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                    ) : (
                                        <span>
                                            · {TIERS.find((t) => t.value === (member.tiers?.[project.id] ?? 'client'))?.label}
                                        </span>
                                    )}
                                </div>
                            ))}

                            {canManage && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setChosen(member.projects);
                                        setEditing(true);
                                    }}
                                    className="text-[11px] text-accent hover:underline"
                                >
                                    Change projects
                                </button>
                            )}
                        </div>
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
                        marked visible to the client within them. <strong>Own issues
                        only</strong> means the ones they reported or were brought
                        into; <strong>all client-visible issues</strong> is for a project
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
                                        {TIERS.map((tier) => (
                                            <option key={tier.value} value={tier.value}>
                                                {tier.label}
                                            </option>
                                        ))}
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

function TierSelect({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (tier: string) => void;
}) {
    return (
        <select
            aria-label={label}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className="shrink-0 rounded border border-border bg-raised px-1.5 py-0.5 text-[11px] text-ink"
        >
            {TIERS.map((tier) => (
                <option key={tier.value} value={tier.value}>
                    {tier.label}
                </option>
            ))}
        </select>
    );
}
