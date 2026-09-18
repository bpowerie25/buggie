import { Button } from '@/components/button';
import { Field, Input, Textarea } from '@/components/field';
import { AppLayout } from '@/layouts/app-layout';
import type { ProjectSummary } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

export default function EditProject({ project }: { project: ProjectSummary }) {
    const { data, setData, put, processing, errors } = useForm({
        name: project.name,
        description: project.description ?? '',
        is_archived: project.is_archived ?? false,
    });
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    function submit(e: FormEvent) {
        e.preventDefault();
        put(`/projects/${project.slug}`);
    }

    return (
        <AppLayout title={`${project.name} settings`}>
            <Head title={`${project.name} settings`} />

            <form onSubmit={submit} className="max-w-lg space-y-4">
                <Field label="Name" error={errors.name}>
                    <Input
                        value={data.name}
                        required
                        onChange={(e) => setData('name', e.target.value)}
                    />
                </Field>

                <Field
                    label="Issue key"
                    hint="Fixed after creation — existing issue keys reference it."
                >
                    <Input value={project.key} disabled className="font-mono" />
                </Field>

                <Field label="Description" error={errors.description}>
                    <Textarea
                        value={data.description}
                        rows={3}
                        onChange={(e) => setData('description', e.target.value)}
                    />
                </Field>

                <label className="flex items-center gap-2 text-sm text-ink-muted">
                    <input
                        type="checkbox"
                        checked={data.is_archived}
                        onChange={(e) => setData('is_archived', e.target.checked)}
                        className="rounded border-border-strong"
                    />
                    Archive this project
                </label>

                <Button type="submit" disabled={processing}>
                    {processing ? 'Saving…' : 'Save changes'}
                </Button>
            </form>

            <section className="mt-12 max-w-lg rounded-xl border border-danger/30 bg-danger-soft p-4">
                <h2 className="text-sm font-semibold text-ink">Delete project</h2>
                <p className="mt-1 text-sm text-ink-muted">
                    Soft-deletes the project and hides its issues. Recoverable by an
                    administrator.
                </p>

                {confirmingDelete ? (
                    <div className="mt-3 flex gap-2">
                        <Button
                            variant="danger"
                            size="sm"
                            onClick={() => router.delete(`/projects/${project.slug}`)}
                        >
                            Yes, delete {project.key}
                        </Button>
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => setConfirmingDelete(false)}
                        >
                            Cancel
                        </Button>
                    </div>
                ) : (
                    <Button
                        variant="danger"
                        size="sm"
                        className="mt-3"
                        onClick={() => setConfirmingDelete(true)}
                    >
                        Delete project
                    </Button>
                )}
            </section>
        </AppLayout>
    );
}
