import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AuthLayout } from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

/** "Acme Ltd" -> "acme-ltd" */
function slugify(value: string) {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40);
}

export default function CreateWorkspace({ domain }: { domain: string }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        slug: '',
    });
    // Stop mirroring the name once the address has been edited by hand.
    const [slugTouched, setSlugTouched] = useState(false);

    function onName(value: string) {
        setData((current) => ({
            ...current,
            name: value,
            slug: slugTouched ? current.slug : slugify(value),
        }));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/workspaces');
    }

    return (
        <AuthLayout
            title="Create your workspace"
            description="One workspace per company. You can create more later."
        >
            <Head title="Create workspace" />

            <form onSubmit={submit} className="space-y-4">
                <Field label="Workspace name" error={errors.name}>
                    <Input
                        value={data.name}
                        autoFocus
                        required
                        placeholder="Acme Ltd"
                        onChange={(e) => onName(e.target.value)}
                    />
                </Field>

                <Field
                    label="Address"
                    error={errors.slug}
                    hint={
                        <>
                            Your workspace will live at{' '}
                            <span className="font-mono text-ink-muted">
                                {data.slug || 'your-workspace'}.{domain}
                            </span>
                        </>
                    }
                >
                    <Input
                        value={data.slug}
                        required
                        placeholder="acme"
                        className="font-mono"
                        onChange={(e) => {
                            setSlugTouched(true);
                            setData('slug', slugify(e.target.value));
                        }}
                    />
                </Field>

                <Button type="submit" disabled={processing} className="w-full">
                    {processing ? 'Creating…' : 'Create workspace'}
                </Button>
            </form>
        </AuthLayout>
    );
}
