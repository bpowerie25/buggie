<?php

namespace App\Enums;

/**
 * Who may make an account on this install, and who may make a workspace.
 *
 * Open is right for the hosted service, where strangers signing up is the business.
 * It is wrong for somebody's own server: a stranger who registers there gets storage,
 * outbound mail and a subdomain of somebody else's domain to put content on.
 */
enum RegistrationMode: string
{
    /** Anyone may register, and anybody signed in may create a workspace. */
    case Open = 'open';

    /** Accounts arrive through workspace invitations; only operators create workspaces. */
    case Invite = 'invite';

    /** As Invite, and a stranger may ask to be let in. */
    case Request = 'request';

    public function allowsAnyoneToRegister(): bool
    {
        return $this === self::Open;
    }

    public function acceptsAccessRequests(): bool
    {
        return $this === self::Request;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Invite => 'By invitation',
            self::Request => 'By invitation, or on request',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Open => 'Anyone can create an account and a workspace of their own.',
            self::Invite => 'People join when a workspace invites them. Only operators create workspaces.',
            self::Request => 'As by invitation, and anyone can ask to be let in. Workspace admins decide.',
        };
    }
}
