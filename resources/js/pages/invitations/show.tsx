import { Button } from '@/components/button';
import { AuthLayout } from '@/layouts/auth-layout';
import { Head, router, usePage } from '@inertiajs/react';

export default function InvitationShow({
    invitation,
    mismatch = false,
    signedInAs,
}: {
    /** Signed in as somebody other than the address it was sent to. */
    mismatch?: boolean;
    signedInAs: string;
    invitation: {
        token: string;
        email: string;
        role: string;
        workspace: string;
        invited_by: string | null;
    };
}) {
    const errors = usePage().props.errors as Record<string, string> | undefined;

    return (
        <AuthLayout title={`Join ${invitation.workspace}`}>
            <Head title={`Join ${invitation.workspace}`} />

            <p className="text-sm text-pretty text-ink-muted">
                {invitation.invited_by ?? 'Someone'} invited{' '}
                <span className="text-ink">{invitation.email}</span> to join{' '}
                <span className="text-ink">{invitation.workspace}</span> as a{' '}
                <span className="text-ink">{invitation.role}</span>.
            </p>

            {mismatch ? (
                <p role="alert" className="mt-5 rounded-lg border border-danger/30 bg-danger-soft p-3 text-sm text-danger">
                    You're signed in as {signedInAs}. This invitation is for {invitation.email}, so sign out and
                    sign in (or register) with that address to accept it.
                </p>
            ) : (
                <>
                    <Button
                        className="mt-5 w-full"
                        onClick={() => router.post(`/invitations/${invitation.token}`)}
                    >
                        Accept invitation
                    </Button>

                    <p className="mt-4 text-xs text-ink-subtle">
                        You're signed in already, so accepting adds this workspace to your account.
                    </p>
                </>
            )}
            {errors?.invitation && (
                <p role="alert" className="mt-3 text-sm text-danger">
                    {errors.invitation}
                </p>
            )}
        </AuthLayout>
    );
}
