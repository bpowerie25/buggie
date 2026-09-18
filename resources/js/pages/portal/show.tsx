import { Button } from '@/components/button';
import { Textarea } from '@/components/field';
import { relativeTime } from '@/components/issue-bits';
import { RichTextView } from '@/components/rich-text';
import { Head, useForm, usePage } from '@inertiajs/react';
import type { JSONContent } from '@tiptap/react';
import { Bug, CheckCircle2, CircleDot } from 'lucide-react';
import type { FormEvent } from 'react';

interface PortalComment {
    id: number;
    body: JSONContent;
    author: string;
    is_you: boolean;
    created_at: string;
}

/**
 * What the person who reported the bug sees.
 *
 * Deliberately not the app: no sidebar, no navigation, no jargon. They came from a
 * link in an email to find out what happened to their report, and the honest answers
 * are "we're looking at it" or "it's done".
 */
export default function PortalShow({
    issue,
    comments,
    token,
    email,
}: {
    issue: {
        key: string;
        title: string;
        description: JSONContent | null;
        project: string;
        state: 'open' | 'closed';
        status: string;
        created_at: string;
    };
    comments: PortalComment[];
    token: string;
    email: string;
}) {
    const { flash } = usePage<{ flash: { success: string | null } }>().props;

    const { data, setData, post, processing, errors, reset } = useForm({ body: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/portal/${token}/comment`, {
            preserveScroll: true,
            onSuccess: () => reset('body'),
        });
    }

    return (
        <>
            <Head title={`${issue.title} · Your report`} />

            <div className="min-h-screen bg-surface py-10">
                <div className="mx-auto w-full max-w-2xl px-4">
                    <div className="mb-6 flex items-center gap-2 text-ink-muted">
                        <Bug className="size-5 text-accent" />
                        <span className="text-sm font-medium">Your bug report</span>
                    </div>

                    <div className="rounded-xl border border-border bg-raised p-6">
                        <div className="flex flex-wrap items-center gap-2">
                            <span
                                className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${
                                    issue.state === 'open'
                                        ? 'bg-accent-soft text-accent'
                                        : 'bg-surface text-ink-muted'
                                }`}
                            >
                                {issue.state === 'open' ? (
                                    <>
                                        <CircleDot className="size-3" />
                                        We're working on it
                                    </>
                                ) : (
                                    <>
                                        <CheckCircle2 className="size-3" />
                                        Closed
                                    </>
                                )}
                            </span>
                            <span className="text-xs text-ink-subtle">
                                {issue.project} · reported {relativeTime(issue.created_at)}
                            </span>
                        </div>

                        <h1 className="mt-3 text-xl font-semibold tracking-tight text-balance text-ink">
                            {issue.title}
                        </h1>

                        {issue.description && (
                            <div className="mt-3 border-t border-border pt-3">
                                <RichTextView value={issue.description} />
                            </div>
                        )}
                    </div>

                    <section className="mt-6">
                        <h2 className="text-xs font-semibold tracking-wide text-ink-subtle uppercase">
                            Messages
                        </h2>

                        {comments.length === 0 ? (
                            <p className="mt-3 text-sm text-ink-muted">
                                No messages yet. We'll post here as we look into it.
                            </p>
                        ) : (
                            <ol className="mt-3 space-y-3">
                                {comments.map((comment) => (
                                    <li
                                        key={comment.id}
                                        className={`rounded-xl border p-4 ${
                                            comment.is_you
                                                ? 'border-border bg-surface'
                                                : 'border-border bg-raised'
                                        }`}
                                    >
                                        <div className="flex items-center gap-2 text-xs">
                                            <span className="font-medium text-ink">
                                                {comment.is_you ? 'You' : comment.author}
                                            </span>
                                            <span className="text-ink-subtle">
                                                {relativeTime(comment.created_at)}
                                            </span>
                                        </div>
                                        <div className="mt-2">
                                            <RichTextView value={comment.body} />
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </section>

                    <form onSubmit={submit} className="mt-6">
                        {flash.success && (
                            <p className="mb-3 text-sm text-success">{flash.success}</p>
                        )}

                        <label
                            htmlFor="portal-reply"
                            className="mb-1.5 block text-sm font-medium text-ink"
                        >
                            Add a message
                        </label>
                        <Textarea
                            id="portal-reply"
                            value={data.body}
                            rows={4}
                            required
                            maxLength={5000}
                            placeholder="Anything else you've noticed?"
                            onChange={(e) => setData('body', e.target.value)}
                        />
                        {errors.body && (
                            <p className="mt-1 text-xs text-danger">{errors.body}</p>
                        )}

                        <Button type="submit" disabled={processing || !data.body.trim()} className="mt-2">
                            {processing ? 'Sending…' : 'Send'}
                        </Button>
                    </form>

                    <p className="mt-8 text-xs text-ink-subtle">
                        This page is private to {email}. Please don't forward the link — anyone
                        with it can read and reply to this report.
                    </p>
                </div>
            </div>
        </>
    );
}
