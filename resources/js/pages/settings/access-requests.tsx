import { AccessRequestList, type AccessRequestRow } from '@/components/access-request-list';
import { AppLayout } from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function AccessRequests({
    requests,
    projects,
    roles,
}: {
    requests: AccessRequestRow[];
    projects: { id: number; name: string; key: string }[];
    roles: { value: string; label: string }[];
}) {
    return (
        <AppLayout title="Access requests">
            <Head title="Access requests" />

            <section className="max-w-2xl">
                <p className="text-sm text-pretty text-ink-muted">
                    People who asked to join this workspace from its sign-in page. Approving
                    sends them an ordinary invitation. Declining sends nothing — they were
                    told only that the request was received.
                </p>

                <div className="mt-6">
                    <AccessRequestList
                        requests={requests}
                        roles={roles}
                        projects={projects}
                        actionBase="/settings/access-requests"
                    />
                </div>
            </section>
        </AppLayout>
    );
}
