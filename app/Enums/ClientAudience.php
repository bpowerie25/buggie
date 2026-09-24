<?php

namespace App\Enums;

/**
 * Which clients see a client-visible issue. Meaningless while visibility is internal.
 *
 * The tier on a client's project grant is the default. These widen it for one issue;
 * none of them narrows it, and none reaches a client who does not hold the project.
 */
enum ClientAudience: string
{
    /** Client managers on the project, plus the reporter and watchers. */
    case Default = 'default';

    /** Every client who holds the project, whatever their tier. */
    case Project = 'project';

    /** The default audience, plus the clients named in issue_client_shares. */
    case Specific = 'specific';
}
