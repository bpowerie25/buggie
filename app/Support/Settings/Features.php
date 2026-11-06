<?php

namespace App\Support\Settings;

use App\Models\Workspace;

/**
 * The parts of a workspace an admin can switch off, and the one permission they can
 * delegate.
 *
 * Stored in `workspaces.settings` rather than a table of its own: they are a handful
 * of booleans read on every page load, with the default being "on" so a workspace that
 * has never been to the settings page behaves as it always did.
 */
final class Features
{
    /** Optional areas, keyed to the label an admin sees. */
    public const OPTIONAL = [
        'timeline' => 'Timeline',
        'time' => 'Time tracking',
        'workload' => 'Workload',
    ];

    /** Whether staff who are not admins may create and edit labels. Off by default. */
    public const MEMBERS_MANAGE_LABELS = 'members_can_manage_labels';

    public static function enabled(?Workspace $workspace, string $feature): bool
    {
        return $workspace === null || ($workspace->settings['features'][$feature] ?? true) !== false;
    }

    /** @return array<string, bool> */
    public static function all(?Workspace $workspace): array
    {
        $all = [];

        foreach (array_keys(self::OPTIONAL) as $key) {
            $all[$key] = self::enabled($workspace, $key);
        }

        return $all;
    }

    public static function membersManageLabels(?Workspace $workspace): bool
    {
        return (bool) ($workspace?->settings[self::MEMBERS_MANAGE_LABELS] ?? false);
    }
}
