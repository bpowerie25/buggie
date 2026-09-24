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
    case VersionChanged = 'version_changed';
    case Related = 'related';
    case Unrelated = 'unrelated';
    case Reopened = 'reopened';
    case Closed = 'closed';
    case Occurrence = 'occurrence';
    case AttachmentAdded = 'attachment_added';
}
