<?php

namespace App\Enums;

enum IssueEventType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case PriorityChanged = 'priority_changed';
    case TypeChanged = 'type_changed';
    case TitleChanged = 'title_changed';
    case LabelAdded = 'label_added';
    case LabelRemoved = 'label_removed';
    case VisibilityChanged = 'visibility_changed';
    /** Which clients see it. Always internal: it names clients, to each other. */
    case AudienceChanged = 'audience_changed';
    /** The team replied and is waiting on the client. Client-visible. */
    case AwaitingClient = 'awaiting_client';
    /** The client answered and the issue went back to where it was. Client-visible. */
    case ClientReplied = 'client_replied';
    /** The client was reminded they owe a reply. Internal: it is the team's record. */
    case ClientReminded = 'client_reminded';
    /** Closed after waiting too long for a reply. Client-visible, with a comment. */
    case AutoClosed = 'auto_closed';
    /** A widget report's reporter was matched to a client member. Internal. */
    case ReporterLinked = 'reporter_linked';
    /** Closed as a duplicate of another issue. Client-visible: it is why their issue closed. */
    case MarkedDuplicate = 'marked_duplicate';
    case VersionChanged = 'version_changed';
    case Related = 'related';
    case Unrelated = 'unrelated';
    case Reopened = 'reopened';
    case Closed = 'closed';
    case Occurrence = 'occurrence';
    case AttachmentAdded = 'attachment_added';

    /**
     * What a client's thread may ever contain: where their issue got to, and the
     * conversation. Checked as well as is_internal, so an event written public by
     * mistake — or by an older rule — still cannot tell a client who holds the
     * issue, who else can see it, or anything about time.
     */
    public function isClientSafe(): bool
    {
        return in_array($this, [
            self::Created, self::StatusChanged, self::Closed, self::Reopened,
            self::AwaitingClient, self::ClientReplied, self::AutoClosed, self::MarkedDuplicate,
        ], true);
    }
}
