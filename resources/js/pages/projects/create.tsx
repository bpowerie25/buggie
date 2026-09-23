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

type TemplateSummary = {
    key: string;
    name: string;
    blurb: string;
    statuses: { name: string; category: string; color: string }[];
    labels: string[];
    fields: string[];
};

type SourceProject = { id: number; name: string; key: string };

export default function CreateProject({
    templates,
    sources,
    defaultTemplate,
}: {
    templates: TemplateSummary[];
    sources: SourceProject[];
    defaultTemplate: string;
}) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        key: '',
        description: '',
        site_url: '',
        template: defaultTemplate,
        source_project_id: '',
    });
    const [keyTouched, setKeyTouched] = useState(false);

    const copying = data.source_project_id !== '';
    const chosen = templates.find((t) => t.key === data.template);

    /** One choice, two fields: picking either one clears the other. */
    function chooseTemplate(key: string) {
        setData((current) => ({ ...current, template: key, source_project_id: '' }));
    }

    function chooseSource(id: string) {
        setData((current) => ({ ...current, template: '', source_project_id: id }));
    }

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
                    label="Where the reporter runs"
                    error={errors.site_url}
                    hint={
                        data.site_url
                            ? `New widget keys will only accept reports from ${originHint(data.site_url)}. You can add more origins per key later.`
                            : 'Usually the UAT or staging site, since that is where testing happens. Leave blank and new widget keys will accept reports from any site.'
                    }
                >
                    <Input
                        type="url"
                        value={data.site_url}
                        placeholder="https://uat.acme.com"
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

                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium text-ink">Start from</legend>
                    <p className="text-xs text-ink-muted">
                        Statuses, labels and custom fields. All of it is editable
                        afterwards, and this only applies now — changing a template
                        later leaves existing projects alone.
                    </p>

                    {templates.map((template) => (
                        <label
                            key={template.key}
                            className="flex cursor-pointer gap-3 rounded-lg border border-border p-3 transition hover:bg-surface"
                        >
                            <input
                                type="radio"
                                name="setup"
                                className="mt-1"
                                checked={!copying && data.template === template.key}
                                onChange={() => chooseTemplate(template.key)}
                            />
                            <span className="block">
                                <span className="block text-sm font-medium text-ink">
                                    {template.name}
                                </span>
                                <span className="block text-xs text-ink-muted">
                                    {template.blurb}
                                </span>
                            </span>
                        </label>
                    ))}

                    {sources.length > 0 && (
                        <label className="flex cursor-pointer gap-3 rounded-lg border border-border p-3 transition hover:bg-surface">
                            <input
                                type="radio"
                                name="setup"
                                className="mt-1"
                                checked={copying}
                                onChange={() => chooseSource(String(sources[0].id))}
                            />
                            <span className="block w-full">
                                <span className="block text-sm font-medium text-ink">
                                    Copy an existing project
                                </span>
                                <span className="block text-xs text-ink-muted">
                                    Its statuses and custom fields. Never its issues,
                                    and never who can see them.
                                </span>
                                <select
                                    value={data.source_project_id}
                                    aria-label="Project to copy"
                                    disabled={!copying}
                                    onChange={(e) => chooseSource(e.target.value)}
                                    className="mt-2 h-[34px] w-full rounded-lg border border-border-strong bg-raised px-2 text-sm text-ink disabled:opacity-60"
                                >
                                    {sources.map((source) => (
                                        <option key={source.id} value={source.id}>
                                            {source.name} ({source.key})
                                        </option>
                                    ))}
                                </select>
                            </span>
                        </label>
                    )}

                    {errors.template && (
                        <span role="alert" className="block text-xs text-danger">
                            {errors.template}
                        </span>
                    )}
                    {errors.source_project_id && (
                        <span role="alert" className="block text-xs text-danger">
                            {errors.source_project_id}
                        </span>
                    )}

                    {!copying && chosen && (
                        <div className="space-y-2 rounded-lg bg-surface p-3">
                            <div className="flex flex-wrap gap-1.5">
                                {chosen.statuses.map((status) => (
                                    <span
                                        key={status.name}
                                        className="rounded px-1.5 py-0.5 text-[11px] font-medium text-white"
                                        style={{ backgroundColor: status.color }}
                                        title={status.category}
                                    >
                                        {status.name}
                                    </span>
                                ))}
                            </div>
                            {chosen.fields.length > 0 && (
                                <p className="text-xs text-ink-muted">
                                    Fields: {chosen.fields.join(', ')}. Internal until
                                    you share them.
                                </p>
                            )}
                            {chosen.labels.length > 0 && (
                                <p className="text-xs text-ink-muted">
                                    Labels: {chosen.labels.join(', ')}. Any that
                                    already exist are left as they are.
                                </p>
                            )}
                        </div>
                    )}
                </fieldset>

                <Button type="submit" disabled={processing}>
                    {processing ? 'Creating…' : 'Create project'}
                </Button>
            </form>
        </AppLayout>
    );
}
