<?php

namespace App\Support\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Install-wide settings, stored in the database and layered over config.
 *
 * Read on every request that sends mail, so it is cached; written rarely, so the
 * cache is simply forgotten on write rather than kept in step.
 */
class Settings
{
    private const CACHE_KEY = 'buggie.settings';

    /** Values held encrypted at rest. A stolen database dump is not a stolen mailbox. */
    private const SECRET = ['mail.password'];

    /** @return array<string, mixed> */
    public function all(): array
    {
        // Rescued rather than propagated: this is read while building config, which
        // happens before migrations have necessarily run — on a first deploy, during
        // `migrate`, and in any command run against an empty database. Mail being
        // unconfigured is survivable; the application failing to boot is not.
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('app_settings')
                ->pluck('value', 'key')
                ->map(fn (?string $value) => $value === null ? null : json_decode($value, true))
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (in_array($key, self::SECRET, true)) {
            try {
                return Crypt::decryptString($value);
            } catch (Throwable) {
                // Encrypted under a previous APP_KEY, so unreadable — the same
                // situation as never having been set.
                return $default;
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    public function put(array $values): void
    {
        foreach ($values as $key => $value) {
            if (in_array($key, self::SECRET, true)) {
                // A blank secret means "leave it alone", not "clear it". The form
                // never sends the current password back, so an empty field is the
                // normal case when editing anything else on the page.
                if ($value === null || $value === '') {
                    continue;
                }

                $value = Crypt::encryptString((string) $value);
            }

            DB::table('app_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
            );
        }

        Cache::forget(self::CACHE_KEY);
    }

    public function forget(string $key): void
    {
        DB::table('app_settings')->where('key', $key)->delete();

        Cache::forget(self::CACHE_KEY);
    }
}
