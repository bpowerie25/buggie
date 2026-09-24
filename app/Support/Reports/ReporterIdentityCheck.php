<?php

namespace App\Support\Reports;

use App\Enums\ReporterIdentity;
use App\Enums\WidgetMode;
use App\Models\WidgetKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Who sent a report, and how sure we are.
 *
 * Decided on the server from what the widget sends: the email and where it came
 * from (identify() or typed), the page's id for the person, and a user_hash if the
 * customer's server signed them. A bad hash is not refused — the report is still a
 * bug somebody hit — it is downgraded to "identified" and logged, because a hash
 * that does not match is either a misconfigured integration or somebody trying it.
 * Only a key that requires verified identity refuses.
 */
final class ReporterIdentityCheck
{
    /**
     * @return array{level: ReporterIdentity, name: ?string, email: ?string, ref: ?string}
     */
    public static function resolve(Request $request, WidgetKey $key): array
    {
        $email = self::clean($request->input('reporter.email'));
        $name = self::clean($request->input('reporter.name'));
        $ref = self::clean($request->input('reporter.ref'));
        $hash = self::clean($request->input('reporter.user_hash'));
        $fromPage = $request->input('reporter.source') === 'identify' || $ref !== null;

        if ($key->mode() === WidgetMode::Anonymous) {
            // Nothing the page says about the person is kept; only what they typed.
            $typed = $request->input('reporter.source') === 'typed' ? $email : null;

            return [
                'level' => $typed ? ReporterIdentity::EmailUnverified : ReporterIdentity::Anonymous,
                'name' => null,
                'email' => $typed,
                'ref' => null,
            ];
        }

        $level = match (true) {
            $hash !== null && $key->verifiesIdentity($ref, $email, $hash) => ReporterIdentity::Verified,
            $fromPage && ($email !== null || $ref !== null) => ReporterIdentity::Identified,
            $email !== null => ReporterIdentity::EmailUnverified,
            default => ReporterIdentity::Anonymous,
        };

        if ($hash !== null && $level !== ReporterIdentity::Verified) {
            // Never the hash or the secret: the key and whose id it claimed to be.
            Log::warning('Widget report carried a user_hash that does not verify; recorded as identified.', [
                'widget_key' => $key->public_key,
                'ref' => $ref,
            ]);

            $level = ReporterIdentity::Identified;
        }

        return ['level' => $level, 'name' => $name, 'email' => $email, 'ref' => $ref];
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
