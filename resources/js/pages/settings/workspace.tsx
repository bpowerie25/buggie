import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AppLayout } from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

export default function WorkspaceSettings({
    workspace,
    domain,
    can_delete,
}: {
    workspace: { name: string; slug: string; created_at: string };
    domain: string;
    can_delete: boolean;
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
