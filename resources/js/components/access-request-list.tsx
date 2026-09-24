import { Button } from '@/components/button';
import { Input } from '@/components/field';
import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

export interface AccessRequestRow {
    id: number;
    name: string;
    email: string;
    organisation: string | null;
    message: string | null;
    status: 'pending' | 'approved' | 'declined';
    created_at: string;
    decided_at: string | null;
    decided_by: string | null;
    decline_reason: string | null;
    /** Only on the operators' list. Null means a request for a new workspace. */
    workspace?: { name: string; slug: string } | null;
}

interface Option {
    value: string;
    label: string;
}

/**
 * Requests to be let in, shared by a workspace's own screen and the operators' one on
 * Settings → Instance. What differs is where decisions are sent and what can be chosen.
 */
export function AccessRequestList({
    requests,
    roles,
    actionBase,
    projects = [],
    workspaces,
}: {
    requests: AccessRequestRow[];
    roles: Option[];
    /** Decisions POST to `${actionBase}/${id}/approve` and `/decline`. */
    actionBase: string;
    /** For granting a client projects. Workspace screen only. */
    projects?: { id: number; name: string }[];
    /** For pointing a request for a new workspace at one. Operators only. */
    workspaces?: { name: string; slug: string }[];
}) {
    if (requests.length === 0) {
        return <p className="text-sm text-ink-muted">No requests yet.</p>;
    }

    return (
        <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-raised">
            {requests.map((request) => (
                <li key={request.id} className="px-4 py-3">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                        <p className="text-sm font-medium text-ink">
                            {request.name}{' '}
                            <span className="font-normal text-ink-muted">{request.email}</span>
                        </p>
                        <p className="text-xs text-ink-subtle">
                            {new Date(request.created_at).toLocaleDateString()}
                        </p>
                    </div>

                    {request.workspace !== undefined && (
                        <p className="mt-0.5 text-xs text-ink-subtle">
                            {request.workspace
                                ? `For ${request.workspace.name}`
                                : `For a new workspace${request.organisation ? ` — ${request.organisation}` : ''}`}
                        </p>
                    )}

                    {request.message && (
                        <p className="mt-2 text-sm whitespace-pre-wrap text-ink-muted">
                            {request.message}
                        </p>
                    )}

                    {request.status === 'pending' ? (
                        <Decision
                            request={request}
                            roles={roles}
                            actionBase={actionBase}
                            projects={projects}
                            workspaces={request.workspace === null ? workspaces : undefined}
                        />
                    ) : (
                        <p className="mt-2 text-xs text-ink-subtle">
                            {request.status === 'approved' ? 'Invited' : 'Declined'}
                            {request.decided_by && ` by ${request.decided_by}`}
                            {request.decided_at && `, ${new Date(request.decided_at).toLocaleDateString()}`}
                            {request.decline_reason && ` — ${request.decline_reason}`}
                        </p>
                    )}
                </li>
            ))}
        </ul>
    );
}

function Decision({
    request,
    roles,
    actionBase,
    projects,
    workspaces,
}: {
    request: AccessRequestRow;
    roles: Option[];
    actionBase: string;
    projects: { id: number; name: string }[];
    workspaces?: { name: string; slug: string }[];
}) {
    const [declining, setDeclining] = useState(false);

    const approval = useForm<{ role: string; project_ids: number[]; workspace?: string }>({
        role: roles.find((role) => role.value === 'member')?.value ?? roles[0]?.value ?? '',
        project_ids: [],
        ...(workspaces ? { workspace: '' } : {}),
    });

    const refusal = useForm({ reason: '' });

    function approve(e: FormEvent) {
        e.preventDefault();
        approval.post(`${actionBase}/${request.id}/approve`, { preserveScroll: true });
    }

    function decline(e: FormEvent) {
        e.preventDefault();
        refusal.post(`${actionBase}/${request.id}/decline`, { preserveScroll: true });
    }

    const select =
        'h-[34px] rounded-lg border border-border-strong bg-raised px-2 text-sm text-ink focus:border-accent focus:outline-none';

    if (declining) {
        return (
            <form onSubmit={decline} className="mt-3 flex flex-wrap items-center gap-2">
                <div className="min-w-56 flex-1">
                    <Input
                        value={refusal.data.reason}
                        maxLength={255}
                        placeholder="Reason, for your own record (optional)"
                        onChange={(e) => refusal.setData('reason', e.target.value)}
                    />
                </div>
                <Button type="submit" variant="secondary" disabled={refusal.processing}>
                    Decline
                </Button>
                <Button type="button" variant="ghost" onClick={() => setDeclining(false)}>
                    Cancel
                </Button>
            </form>
        );
    }

    return (
        <form onSubmit={approve} className="mt-3 space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                {workspaces && (
                    <select
                        aria-label="Workspace"
                        value={approval.data.workspace}
                        required
                        onChange={(e) => approval.setData('workspace', e.target.value)}
                        className={select}
                    >
                        <option value="">Invite to…</option>
                        {workspaces.map((w) => (
                            <option key={w.slug} value={w.slug}>
                                {w.name}
                            </option>
                        ))}
                    </select>
                )}

                <select
                    aria-label="Role"
                    value={approval.data.role}
                    onChange={(e) => approval.setData('role', e.target.value)}
                    className={select}
                >
                    {roles.map((role) => (
                        <option key={role.value} value={role.value}>
                            as {role.label.toLowerCase()}
                        </option>
                    ))}
                </select>

                <Button type="submit" size="sm" disabled={approval.processing}>
                    Approve and invite
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setDeclining(true)}>
                    Decline…
                </Button>
            </div>

            {approval.data.role === 'client' && (
                <div className="flex flex-wrap gap-1.5">
                    {projects.map((project) => {
                        const on = approval.data.project_ids.includes(project.id);

                        return (
                            <button
                                key={project.id}
                                type="button"
                                aria-pressed={on}
                                onClick={() =>
                                    approval.setData(
                                        'project_ids',
                                        on
                                            ? approval.data.project_ids.filter((id) => id !== project.id)
                                            : [...approval.data.project_ids, project.id],
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
            )}

            {Object.values(approval.errors).map((error) => (
                <p key={error} className="text-xs text-danger">
                    {error}
                </p>
            ))}
        </form>
    );
}
