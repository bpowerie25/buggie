export type WorkspaceRole = 'owner' | 'admin' | 'member' | 'client';

export type StatusCategory =
    | 'backlog'
    | 'unstarted'
    | 'started'
    | 'done'
    | 'canceled';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    initials: string;
    avatar_url: string | null;
}

export interface WorkspaceSummary {
    id: number;
    name: string;
    slug: string;
}

export interface WorkspaceListing {
    name: string;
    slug: string;
    url: string;
    role: WorkspaceRole;
}

export interface ProjectSummary {
    name: string;
    key: string;
    slug: string;
    description: string | null;
    /** Seeds the origin allowlist of new widget keys. */
    site_url: string | null;
    is_archived?: boolean;
}

export interface Status {
    id: number;
    name: string;
    category: StatusCategory;
    color: string;
    position: number;
    is_default: boolean;
    open: boolean;
}

export interface SharedProps {
    auth: {
        user: AuthUser | null;
        role: WorkspaceRole | null;
        /** Operates the whole install, not just this workspace. */
        operator: boolean;
    };
    workspace: WorkspaceSummary | null;
    /** Absolute: the guide is served from the central domain, not a workspace. */
    docsUrl: string;
    workspaces: WorkspaceListing[];
    views: SavedView[];
    inboxCount: number;
    billing: {
        plan: string;
        usage: Record<string, { used: number; limit: number | null; over: boolean; near: boolean }>;
        on_trial: boolean;
        trial_days_left: number;
        can_manage: boolean;
    } | null;
    /** Present only when mail would not actually be delivered. */
    mail: { deliverable: false; can_fix: boolean } | null;
    /** Operators only, and only when something about the backups needs saying. */
    backups: { warning: string; severe: boolean } | null;
    flash: { success: string | null; error: string | null };
    [key: string]: unknown;
}

export type IssueTypeValue = 'bug' | 'feature' | 'task' | 'question';
export type VisibilityValue = 'internal' | 'client';
export type RelationTypeValue = 'blocks' | 'blocked_by' | 'relates_to' | 'duplicates';

export interface Person {
    id: number;
    name: string;
}

export interface LabelChip {
    id: number;
    name: string;
    color: string;
}

export interface IssueStatus {
    id: number;
    name: string;
    category: StatusCategory;
    color: string;
    position: number;
    open: boolean;
    /** How many issues should sit here at once. Null means no limit. */
    wip_limit?: number | null;
}

export interface IssueRow {
    id: number;
    key: string;
    title: string;
    type: IssueTypeValue;
    priority: number;
    priority_label: string;
    priority_color: string;
    status: IssueStatus;
    assignee: Person | null;
    labels: LabelChip[];
    project: { id: number; key: string; name: string; slug: string };
    updated_at: string;
}

export interface IssueFilters {
    q: string | null;
    project: string | null;
    state: 'open' | 'closed' | 'all';
    assignee: string | null;
    label: number | null;
    type: string | null;
    priority: number | null;
}

export interface Facets {
    projects: { id: number; name: string; key: string; slug: string }[];
    labels: LabelChip[];
    members: Person[];
    priorities: { value: number; label: string; color: string }[];
    types: { value: string; label: string }[];
    statuses_by_project: Record<number, IssueStatus[]>;
}

export interface SavedView {
    id: number;
    name: string;
    query: string;
    layout: 'list' | 'board';
    group_by: 'status' | 'assignee' | 'priority' | 'project';
    shared: boolean;
    can_edit: boolean;
}

export interface ReportRow {
    id: number;
    ids: number[];
    count: number;
    title: string;
    body: string | null;
    project: { key: string; name: string; slug: string };
    reporter: { name: string | null; email: string | null };
    environment: Record<string, unknown>;
    console: { level: string; message: string; at: number }[];
    network: { method: string; url: string; status: number | string; duration: number }[];
    error: { message?: string; stack?: string } | null;
    fingerprint: string | null;
    screenshot_url: string | null;
    created_at: string;
}
