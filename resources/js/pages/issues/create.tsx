import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { StatusDot, TypeIcon } from '@/components/issue-bits';
import { Popover, PopoverItem } from '@/components/popover';
import { RichTextEditor, type Mentionable } from '@/components/rich-text';
import { AppLayout } from '@/layouts/app-layout';
import type { Facets, IssueStatus, IssueTypeValue, SharedProps } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    CustomFieldInput,
    type CustomFieldDefinition,
} from '@/components/custom-field-input';
import type { JSONContent } from '@tiptap/react';
import { Eye, EyeOff, Paperclip, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';

export default function CreateIssue({
    project,
    facets,
    statuses,
    customFields = [],
    mentionable = [],
}: {
    project: { id: number; key: string; name: string; slug: string };
    facets: Facets;
    statuses: IssueStatus[];
    customFields?: CustomFieldDefinition[];
    /** Staff only: a new issue is shared with nobody yet. */
    mentionable?: Mentionable[];
}) {
    const { auth } = usePage<SharedProps>().props;
    const isStaff = auth.role !== 'client';

    const { data, setData, post, processing, errors } = useForm<{
        project_id: number;
        title: string;
        description: JSONContent | null;
        type: IssueTypeValue;
        priority: number;
        assignee_id: number | null;
        status_id: number | null;
        visibility: 'internal' | 'client';
        labels: number[];
        custom_fields: Record<string, string | null>;
    }>({
        project_id: project.id,
        title: '',
        description: null,
        type: 'bug',
        priority: 0,
        assignee_id: null,
        status_id: null,
        visibility: isStaff ? 'internal' : 'client',
        labels: [],
        custom_fields: {},
    });

    const [files, setFiles] = useState<File[]>([]);
    const [sending, setSending] = useState(false);
    const fileError = Object.entries(errors).find(([key]) => key.startsWith('files'))?.[1];

    function submit(e: FormEvent) {
        e.preventDefault();

        if (files.length === 0) {
            post('/issues');
            return;
        }

        // Multipart flattens the rich-text document, so everything but the files
        // travels as one JSON field and is unpacked on the server.
        router.post(
            '/issues',
            { payload: JSON.stringify(data), files },
            { forceFormData: true, onStart: () => setSending(true), onFinish: () => setSending(false) },
        );
    }

    const selectedProject =
        facets.projects.find((p) => p.id === data.project_id) ?? project;

    return (
        <AppLayout title="New issue">
            <Head title="New issue" />

            <form onSubmit={submit} className="max-w-2xl space-y-5">
                <div className="flex flex-wrap items-center gap-2">
                    <Popover
                        label="Project"
                        trigger={() => (
                            <span className="flex items-center gap-2 rounded-lg border border-border px-2 py-1 text-xs text-ink">
                                <span className="font-mono text-ink-subtle">
                                    {selectedProject.key}
                                </span>
                                {selectedProject.name}
                            </span>
                        )}
                    >
                        {(close) =>
                            facets.projects.map((option) => (
                                <PopoverItem
                                    key={option.id}
                                    selected={option.id === data.project_id}
                                    onSelect={() => {
                                        close();
                                        // Statuses belong to a project, so switching
                                        // reloads the page to pick up the right ones.
                                        window.location.href = `/issues/create?project=${option.slug}`;
                                    }}
                                >
                                    <span className="font-mono text-[11px] text-ink-subtle">
                                        {option.key}
                                    </span>
                                    <span className="truncate">{option.name}</span>
                                </PopoverItem>
                            ))
                        }
                    </Popover>

                    <Popover
                        label="Type"
                        trigger={() => (
                            <span className="flex items-center gap-2 rounded-lg border border-border px-2 py-1 text-xs text-ink capitalize">
                                <TypeIcon type={data.type} />
                                {data.type}
                            </span>
                        )}
                    >
                        {(close) =>
                            facets.types.map((option) => (
                                <PopoverItem
                                    key={option.value}
                                    selected={option.value === data.type}
                                    onSelect={() => {
                                        close();
                                        setData('type', option.value as IssueTypeValue);
                                    }}
                                >
                                    <TypeIcon type={option.value as IssueTypeValue} />
                                    {option.label}
                                </PopoverItem>
                            ))
                        }
                    </Popover>

                    {isStaff && (
                        <>
                            <Popover
                                label="Priority"
                                trigger={() => (
                                    <span className="rounded-lg border border-border px-2 py-1 text-xs text-ink">
                                        {facets.priorities.find(
                                            (p) => p.value === data.priority,
                                        )?.label}
                                    </span>
                                )}
                            >
                                {(close) =>
                                    facets.priorities.map((option) => (
                                        <PopoverItem
                                            key={option.value}
                                            selected={option.value === data.priority}
                                            onSelect={() => {
                                                close();
                                                setData('priority', option.value);
                                            }}
                                        >
                                            {option.label}
                                        </PopoverItem>
                                    ))
                                }
                            </Popover>

                            <button
                                type="button"
                                onClick={() =>
                                    setData(
                                        'visibility',
                                        data.visibility === 'client' ? 'internal' : 'client',
                                    )
                                }
                                className="flex items-center gap-1.5 rounded-lg border border-border px-2 py-1 text-xs text-ink-muted transition hover:text-ink"
                            >
                                {data.visibility === 'client' ? (
                                    <>
                                        <Eye className="size-3" /> Client can see this
                                    </>
                                ) : (
                                    <>
                                        <EyeOff className="size-3" /> Internal only
                                    </>
                                )}
                            </button>
                        </>
                    )}
                </div>

                <Field label="Title" error={errors.title}>
                    <Input
                        value={data.title}
                        autoFocus
                        required
                        placeholder="What went wrong?"
                        onChange={(e) => setData('title', e.target.value)}
                    />
                </Field>

                <div className="space-y-1.5">
                    <span className="text-sm font-medium text-ink">Description</span>
                    <RichTextEditor
                        value={data.description}
                        onChange={(value) => setData('description', value)}
                        mentions={mentionable}
                        placeholder="What happens, what you expected instead, and how to reproduce it."
                    />
                    {errors.description && (
                        <span className="text-xs text-danger">{errors.description}</span>
                    )}
                </div>

                {customFields.length > 0 && (
                    <div className="space-y-4 rounded-xl border border-border p-4">
                        {customFields.map((field) => (
                            <Field
                                key={field.key}
                                label={field.name + (field.required ? ' *' : '')}
                                error={errors[`custom_fields.${field.key}` as keyof typeof errors]}
                            >
                                <CustomFieldInput
                                    field={field}
                                    value={data.custom_fields[field.key] ?? null}
                                    onChange={(value) =>
                                        setData('custom_fields', {
                                            ...data.custom_fields,
                                            [field.key]: value,
                                        })
                                    }
                                />
                            </Field>
                        ))}
                    </div>
                )}

                {isStaff && statuses.length > 0 && (
                    <Field label="Starting status">
                        <div className="flex flex-wrap gap-1.5">
                            {statuses
                                .filter((s) => s.open)
                                .map((status) => (
                                    <button
                                        key={status.id}
                                        type="button"
                                        onClick={() => setData('status_id', status.id)}
                                        aria-pressed={data.status_id === status.id}
                                        className={`flex items-center gap-1.5 rounded-lg border px-2 py-1 text-xs transition ${
                                            data.status_id === status.id
                                                ? 'border-accent bg-accent-soft text-accent'
                                                : 'border-border text-ink-muted hover:text-ink'
                                        }`}
                                    >
                                        <StatusDot status={status} />
                                        {status.name}
                                    </button>
                                ))}
                        </div>
                    </Field>
                )}

                <div className="space-y-1.5">
                    <span className="text-sm font-medium text-ink">Attachments</span>
                    <label className="flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-border-strong px-3 py-2.5 text-sm text-ink-muted transition hover:border-accent hover:text-ink">
                        <Paperclip className="size-4" />
                        Add screenshots or files
                        <span className="text-xs text-ink-subtle">
                            images, PDF, text, CSV, JSON, ZIP · up to 10 MB each
                        </span>
                        <input
                            type="file"
                            multiple
                            className="sr-only"
                            onChange={(e) => {
                                setFiles((current) => [...current, ...Array.from(e.target.files ?? [])].slice(0, 10));
                                e.target.value = '';
                            }}
                        />
                    </label>
                    {files.length > 0 && (
                        <ul className="space-y-1">
                            {files.map((file, index) => (
                                <li key={`${file.name}-${index}`} className="flex items-center gap-2 text-xs text-ink-muted">
                                    <span className="min-w-0 flex-1 truncate">{file.name}</span>
                                    <span className="text-ink-subtle">{Math.max(1, Math.round(file.size / 1024))} KB</span>
                                    <button
                                        type="button"
                                        aria-label={`Remove ${file.name}`}
                                        onClick={() => setFiles((current) => current.filter((_, i) => i !== index))}
                                        className="rounded p-0.5 text-ink-subtle hover:text-danger"
                                    >
                                        <X className="size-3.5" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {fileError && <span className="text-xs text-danger">{fileError}</span>}
                </div>

                <Button type="submit" disabled={processing || sending || !data.title}>
                    {processing || sending ? 'Creating…' : `Create issue in ${selectedProject.key}`}
                </Button>
            </form>
        </AppLayout>
    );
}
