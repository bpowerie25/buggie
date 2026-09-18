import { Button } from '@/components/button';
import { AuthLayout } from '@/layouts/auth-layout';
import { Head, router } from '@inertiajs/react';

export default function InvitationShow({
    invitation,
}: {
    invitation: {
        token: string;
        email: string;
        role: string;
        workspace: string;
        invited_by: string | null;
    };
}) {
    return (
        <AuthLayout title={`Join ${invitation.workspace}`}>
            <Head title={`Join ${invitation.workspace}`} />

            <p className="text-sm text-pretty text-ink-muted">
                {invitation.invited_by ?? 'Someone'} invited{' '}
                <span className="text-ink">{invitation.email}</span> to join{' '}
                <span className="text-ink">{invitation.workspace}</span> as a{' '}
                <span className="text-ink">{invitation.role}</span>.
            </p>

            <Button
                className="mt-5 w-full"
                onClick={() => router.post(`/invitations/${invitation.token}`)}
            >
                Accept invitation
            </Button>

            <p className="mt-4 text-xs text-ink-subtle">
                You're signed in already, so accepting adds this workspace to your account.
            </p>
        </AuthLayout>
    );
}
