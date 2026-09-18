import { Head } from '@inertiajs/react';
import { Bug } from 'lucide-react';

export default function PortalInvalid() {
    return (
        <>
            <Head title="Link expired" />

            <div className="flex min-h-screen items-center justify-center bg-surface px-4">
                <div className="w-full max-w-md rounded-xl border border-border bg-raised p-8 text-center">
                    <Bug className="mx-auto size-6 text-ink-subtle" />
                    <h1 className="mt-4 text-lg font-semibold text-ink">
                        This link has expired
                    </h1>
                    <p className="mt-2 text-sm text-pretty text-ink-muted">
                        Links to a bug report last 90 days. If you still need an update, reply
                        to the last email you had from us and we'll pick it up from there.
                    </p>
                </div>
            </div>
        </>
    );
}
