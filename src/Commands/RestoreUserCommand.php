<?php

namespace Tey\Concierge\Commands;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tey\Concierge\Actions\RestoreUser;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Exceptions\CommandRefused;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\AppScope;
use Tey\Concierge\Support\ConciergeModels;

/**
 * Restores a deactivated user from the terminal as a named actor: the same
 * RestoreUser action the Users page calls, with --as naming the user it runs
 * as. Every rule the action applies holds unchanged, so the terminal is a
 * shortcut for the rules, never around them.
 */
class RestoreUserCommand extends Command
{
    public $signature = 'concierge:restore
        {email : The email of the user to restore}
        {--as= : The email of the existing user to run the action as}
        {--dry-run : Resolve both users and run the action authorization check, writing nothing}
        {--json : Print user and outcome as JSON}';

    public $description = 'Restore a deactivated user, with the roles they had. Runs as the --as user.';

    public function handle(): int
    {
        try {
            ConciergeModels::assertModeSupported();
            ConciergeModels::assertAppWide("concierge:restore is app-wide only; in workspace mode restore the user through your own AccountPolicy, or set 'mode' => 'app' in config/concierge.php");

            $actor = $this->resolve((string) $this->option('as'), '--as');
            $user = $this->resolve((string) $this->argument('email'), 'email');
            $action = app(RestoreUser::class);

            if ($this->option('dry-run')) {
                $action->authorize($actor, $user, app(AppScope::class)->account($user));
            } else {
                $action->handle($actor, $user);
            }
        } catch (AuthorizationException $exception) {
            return $this->refuse($exception->getMessage(), (string) $exception->getCode());
        } catch (CommandRefused $refused) {
            return $this->refuse($refused->getMessage(), $refused->errorCode);
        } catch (UnsupportedConfiguration $exception) {
            return $this->refuse($exception->getMessage(), 'unsupported_configuration');
        } catch (QueryException $exception) {
            return $this->refuse(
                $exception->getMessage().' Check database.default in config/database.php and run php artisan migrate before retrying php artisan concierge:restore.',
                'database',
            );
        }

        $outcome = $this->option('dry-run') ? 'would_restore' : 'restored';

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'user' => ['id' => (int) $user->getKey(), 'email' => $user->conciergeEmail()],
                'outcome' => $outcome,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line("{$user->conciergeEmail()}: ".str_replace('_', ' ', $outcome));

        return self::SUCCESS;
    }

    /**
     * The user an email names, matched ignoring case. A refusal names where the
     * email came from: the command is not a way around the action's actor rule.
     *
     * @return Model&Member
     */
    protected function resolve(string $email, string $source): Member
    {
        $email = trim($email);

        if ($email === '') {
            throw new CommandRefused(
                $source === '--as'
                    ? 'concierge:restore needs --as=<email> naming the existing user to run the action as.'
                    : 'concierge:restore needs the email of the user to restore.',
                $source === '--as' ? 'missing_actor' : 'missing_user',
            );
        }

        /** @var class-string<Model&Member> $model */
        $model = ConciergeModels::user();

        /** @var (Model&Member)|null $user */
        $user = $model::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();

        if (! $user instanceof Member) {
            throw new CommandRefused(
                "No user found for [{$email}].".($source === '--as' ? ' The --as user must exist.' : ''),
                $source === '--as' ? 'unknown_actor' : 'unknown_user',
            );
        }

        return $user;
    }

    protected function refuse(string $message, string $code): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['error' => $message, 'code' => $code], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
