<?php

namespace App\Support\Mail;

use App\Models\Issue;
use App\Models\User;

/**
 * The address a digest can be answered at: reply+i{issue}.u{user}.{signature}@…
 *
 * One address per issue per recipient, signed with the application key. A reply to
 * it is posted as the person it was sent to — never as whoever the From: header
 * names, which anybody can write — and only to that one issue. Forwarded, it lets
 * the forwardee answer on that one thread as that one person, which is what
 * forwarding an email is generally taken to mean; it reaches nothing else.
 *
 * The address used to be the issue key plus the project's inbound token, the same
 * for everybody. Every client was sent the token, and with it could comment on any
 * issue in the project, internal ones included, as anyone they cared to name.
 */
final class ReplyAddress
{
    public static function for(Issue $issue, User $user): string
    {
        return 'reply+'.self::token($issue->id, $user->id).'@'.config('buggie.inbound_domain');
    }

    public static function token(int $issueId, int $userId): string
    {
        return "i{$issueId}.u{$userId}.".self::signature($issueId, $userId);
    }

    /**
     * The issue and person a reply token was issued for, or null for anything not
     * issued by this application.
     *
     * @return array{issue: int, user: int}|null
     */
    public static function verify(string $token): ?array
    {
        if (! preg_match('/^i(\d+)\.u(\d+)\.([0-9a-f]{32})$/', strtolower($token), $m)) {
            return null;
        }

        return hash_equals(self::signature((int) $m[1], (int) $m[2]), $m[3])
            ? ['issue' => (int) $m[1], 'user' => (int) $m[2]]
            : null;
    }

    private static function signature(int $issueId, int $userId): string
    {
        return substr(hash_hmac('sha256', "buggie-reply|{$issueId}|{$userId}", (string) config('app.key')), 0, 32);
    }
}
