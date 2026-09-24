<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Operators\Operators;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Make somebody an operator from the command line, creating their account if need be.
 *
 * For installs set up without a browser, and for getting back in: the first-run
 * exception is taken once and never offered again, so an install whose operator has
 * left needs a way to name another that does not involve opening a database console.
 *
 * Writes users.is_operator. BUGGIE_OPERATORS still works alongside it, and this
 * command cannot remove somebody that list names — it says so rather than pretend.
 */
class ManageOperator extends Command
{
    protected $signature = 'buggie:operator
        {email? : The operator\'s email address}
        {--name= : Their name, if the account has to be created}
        {--revoke : Stop them being an operator}
        {--list : Show who operates this install}';

    protected $description = 'Create or promote an operator, revoke one, or list them';

    public function handle(Operators $operators): int
    {
        if ($this->option('list')) {
            return $this->list($operators);
        }

        $email = strtolower(trim((string) $this->argument('email')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Give an email address, or --list.');

            return self::INVALID;
        }

        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        return $this->option('revoke') ? $this->revoke($user, $email) : $this->promote($user, $email);
    }

    private function promote(?User $user, string $email): int
    {
        if ($user !== null) {
            if ($user->is_operator) {
                $this->info("{$user->email} is already an operator.");

                return self::SUCCESS;
            }

            $user->forceFill(['is_operator' => true])->save();
            $this->info("{$user->email} is now an operator.");

            return self::SUCCESS;
        }

        $name = (string) ($this->option('name') ?: ($this->input->isInteractive()
            ? $this->ask('Name', Str::headline(Str::before($email, '@')))
            : Str::headline(Str::before($email, '@'))));

        $password = $this->input->isInteractive()
            ? (string) $this->secret('Password (leave blank to generate one)')
            : '';

        $generated = $password === '';

        if ($generated) {
            $password = Str::password(20, symbols: false);
        } elseif (mb_strlen($password) < 8) {
            $this->error('Use at least eight characters.');

            return self::INVALID;
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['is_operator' => true])->save();

        $this->info("Created {$email} as an operator.");

        if ($generated) {
            // Shown once and stored nowhere. Printed rather than mailed, because this
            // is also how an install with no working mail gets its first operator.
            $this->line("Password: <comment>{$password}</comment>");
            $this->line('Sign in and change it, or use "Forgot password" once mail works.');
        }

        $this->line('Sign in to create the first workspace and configure the rest under Settings → Instance.');

        return self::SUCCESS;
    }

    private function revoke(?User $user, string $email): int
    {
        if ($user !== null && $user->is_operator) {
            $user->forceFill(['is_operator' => false])->save();
            $this->info("{$user->email} is no longer marked as an operator.");
        } elseif ($user === null) {
            $this->warn("There is no account for {$email}.");
        }

        if (in_array($email, array_map('strtolower', (array) config('buggie.operators')), true)) {
            $this->warn("{$email} is still named in BUGGIE_OPERATORS, which keeps them an operator. Remove them there too.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function list(Operators $operators): int
    {
        $named = array_map('strtolower', (array) config('buggie.operators'));

        $rows = $operators->all()->map(fn (User $user) => [
            $user->email,
            $user->name,
            collect([
                $user->is_operator ? 'stored' : null,
                in_array(strtolower($user->email), $named, true) ? 'BUGGIE_OPERATORS' : null,
            ])->filter()->implode(', '),
        ]);

        // Named in the environment but never signed up: operators in waiting, worth
        // seeing, because a typo here is an operator who can never get in.
        $missing = array_diff($named, $operators->all()->map(fn (User $u) => strtolower($u->email))->all());

        foreach ($missing as $email) {
            $rows->push([$email, '—', 'BUGGIE_OPERATORS (no account yet)']);
        }

        if ($rows->isEmpty()) {
            $this->warn('Nobody operates this install. Run: php artisan buggie:operator you@example.com');

            return self::SUCCESS;
        }

        $this->table(['Email', 'Name', 'Because'], $rows->all());

        return self::SUCCESS;
    }
}
