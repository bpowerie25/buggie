import { router } from '@inertiajs/react';
import { FileText, Paperclip, Trash2, Upload } from 'lucide-react';
import { useCallback, useRef, useState, type DragEvent } from 'react';

export interface AttachmentRow {
    id: number;
    filename: string;
    mime: string;
    size: number;
    url: string;
    is_image: boolean;
    uploaded_by: string | null;
    created_at: string;
}

function formatSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * Drop zone and list for an issue's attachments.
 *
 * Accepts drag-and-drop and paste, because the two things people actually have when
 * reporting a bug are a screenshot in the clipboard and a log file on the desktop.
 */
export function Attachments({
    issueKey,
    attachments,
    canUpload,
    canDelete,
}: {
    issueKey: string;
    attachments: AttachmentRow[];
    canUpload: boolean;
    canDelete: boolean;
}) {
    const [dragging, setDragging] = useState(false);
    const [uploading, setUploading] = useState<string[]>([]);
    const [error, setError] = useState<string | null>(null);
    const input = useRef<HTMLInputElement>(null);

    const upload = useCallback(
        (files: FileList | File[]) => {
            setError(null);

            for (const file of Array.from(files)) {
                setUploading((current) => [...current, file.name]);

                router.post(
                    `/issues/${issueKey}/attachments`,
                    { file },
                    {
                        forceFormData: true,
                        preserveScroll: true,
                        only: ['issue', 'attachments', 'events', 'flash', 'errors'],
                        onError: (errors) =>
                            setError(errors.file ?? 'That file could not be attached.'),
                        onFinish: () =>
                            setUploading((current) => current.filter((n) => n !== file.name)),
                    },
                );
            }
        },
        [issueKey],
    );

    function onDrop(event: DragEvent) {
        event.preventDefault();
        setDragging(false);

        if (event.dataTransfer.files.length > 0) upload(event.dataTransfer.files);
    }

    return (
        <section
            onDragOver={(e) => {
                if (!canUpload) return;
                e.preventDefault();
                setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={canUpload ? onDrop : undefined}
            onPaste={(e) => {
                if (!canUpload) return;
                const files = Array.from(e.clipboardData.files);
                if (files.length > 0) upload(files);
            }}
            className={`rounded-xl transition ${
                dragging ? 'bg-accent-soft ring-2 ring-accent ring-inset' : ''
            }`}
        >
            <div className="flex items-center gap-2">
                <h2 className="text-xs font-semibold tracking-wide text-ink-subtle uppercase">
                    Attachments
                </h2>

                {canUpload && (
                    <>
                        <button
                            type="button"
                            onClick={() => input.current?.click()}
                            className="flex items-center gap-1 text-[11px] text-ink-muted transition hover:text-ink"
                        >
                            <Paperclip className="size-3" />
                            Add
                        </button>
                        <input
                            ref={input}
                            type="file"
                            multiple
                            className="sr-only"
                            onChange={(e) => e.target.files && upload(e.target.files)}
                        />
                        <span className="text-[11px] text-ink-subtle">
                            or drop files here
                        </span>
                    </>
                )}
            </div>

            {error && <p className="mt-2 text-xs text-danger">{error}</p>}

            {uploading.length > 0 && (
                <p className="mt-2 flex items-center gap-1.5 text-xs text-ink-muted">
                    <Upload className="size-3 animate-pulse" />
                    Uploading {uploading.join(', ')}…
                </p>
            )}

            {attachments.length > 0 && (
                <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                    {attachments.map((attachment) => (
                        <li
                            key={attachment.id}
                            className="group relative overflow-hidden rounded-lg border border-border bg-raised"
                        >
                            {attachment.is_image ? (
                                <a href={attachment.url} target="_blank" rel="noreferrer">
                                    <img
                                        src={attachment.url}
                                        alt={attachment.filename}
                                        loading="lazy"
                                        className="h-32 w-full object-cover"
                                    />
                                </a>
                            ) : (
                                <a
                                    href={attachment.url}
                                    className="flex h-32 items-center justify-center"
                                >
                                    <FileText className="size-8 text-ink-subtle" />
                                </a>
                            )}

                            <div className="flex items-center gap-2 border-t border-border px-2.5 py-1.5">
                                <a
                                    href={attachment.url}
                                    className="min-w-0 flex-1 truncate text-xs text-ink hover:text-accent"
                                    title={attachment.filename}
                                >
                                    {attachment.filename}
                                </a>
                                <span className="shrink-0 text-[10px] text-ink-subtle">
                                    {formatSize(attachment.size)}
                                </span>

                                {canDelete && (
                                    <button
                                        type="button"
                                        aria-label={`Remove ${attachment.filename}`}
                                        onClick={() =>
                                            router.delete(`/attachments/${attachment.id}`, {
                                                preserveScroll: true,
                                                only: ['attachments', 'flash'],
                                            })
                                        }
                                        className="shrink-0 rounded p-0.5 text-ink-subtle opacity-0 transition group-hover:opacity-100 hover:text-danger focus-visible:opacity-100"
                                    >
                                        <Trash2 className="size-3" />
                                    </button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {attachments.length === 0 && canUpload && (
                <p className="mt-2 text-xs text-ink-subtle">
                    Screenshots, logs and PDFs. Paste an image straight in.
                </p>
            )}
        </section>
    );
}
