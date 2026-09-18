import { Button } from '@/components/button';
import { Field, Input, Textarea } from '@/components/field';
import { AppLayout } from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

/** Mirrors CreateProject::suggestKey() so the field previews the real default. */
function suggestKey(name: string) {
    const words = name.split(/[^A-Za-z0-9]+/).filter(Boolean);
    if (words.length === 0) return '';
    const base =
        words.length > 1
            ? words.slice(0, 4).map((w) => w[0]).join('')
            : words[0].slice(0, 3);
    return base.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
}

/**
 * What the origin allowlist will end up being, shown while they type.
 *
 * Mirrors Project::defaultWidgetOrigins(): an origin, plus its www/apex sibling,
 * because a site served at both sends whichever one the visitor was on.
 */
function originHint(value: string): string {
    try {
        const url = new URL(value);
        const host = url.hostname.toLowerCase();
        const sibling = host.startsWith('www.') ? host.slice(4) : `www.${host}`;

        return `${url.protocol}//${host}${url.port ? `:${url.port}` : ''} and ${url.protocol}//${sibling}`;
    } catch {
        return value;
    }
}

export default function CreateProject() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        key: '',
        description: '',
        site_url: '',
    });
    const [keyTouched, setKeyTouched] = useState(false);

    function onName(value: string) {
        setData((current) => ({
            ...current,
            name: value,
            key: keyTouched ? current.key : suggestKey(value),
        }));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/projects');
    }

    return (
        <AppLayout title="New project">
            <Head title="New project" />

            <form onSubmit={submit} className="max-w-lg space-y-4">
                <Field label="Name" error={errors.name}>
                    <Input
                        value={data.name}
                        autoFocus
                        required
                        placeholder="Marketing site"
                        onChange={(e) => onName(e.target.value)}
                    />
                </Field>

                <Field
                    label="Issue key"
                    error={errors.key}
                    hint={`Issues will be numbered ${data.key || 'KEY'}-1, ${data.key || 'KEY'}-2, and so on. This can't be changed later.`}
                >
                    <Input
                        value={data.key}
                        placeholder="MS"
                        maxLength={6}
                        className="font-mono uppercase"
                        onChange={(e) => {
                            setKeyTouched(true);
                            setData(
                                'key',
                                e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ''),
                            );
                        }}
                    />
                </Field>

                <Field
                    label="Site URL"
                    error={errors.site_url}
                    hint={
                        data.site_url
                            ? `The reporter widget will only accept reports from ${originHint(data.site_url)}. You can change this later.`
                            : 'Where this application runs. Leave blank and the reporter widget will accept reports from any site.'
                    }
                >
                    <Input
                        type="url"
                        value={data.site_url}
                        placeholder="https://acme.com"
                        onChange={(e) => setData('site_url', e.target.value)}
                    />
                </Field>

                <Field label="Description" error={errors.description}>
                    <Textarea
                        value={data.description}
                        rows={3}
                        placeholder="Optional."
                        onChange={(e) => setData('description', e.target.value)}
                    />
                </Field>

                <Button type="submit" disabled={processing}>
                    {processing ? 'Creating…' : 'Create project'}
                </Button>
            </form>
        </AppLayout>
    );
}
