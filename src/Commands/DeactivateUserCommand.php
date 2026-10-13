<?php

namespace Tey\Concierge\Commands;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tey\Concierge\Actions\DeactivateUser;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Events\UserDeactivated;
use Tey\Concierge\Exceptions\CommandRefused;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\AppScope;
use Tey\Concierge\Support\ConciergeModels;

/**
 * Deactivates a user from the terminal as a named actor: the same
 * DeactivateUser action the Users page calls, with --as naming the user it runs
 * as. Every rule the action applies holds unchanged, so the terminal is a
 * shortcut for the rules, never around them.
 */
class DeactivateUserCommand extends Command
{
    public $signature = 'concierge:deactivate
        {email : The email of the user to deactivate}
        {--as= : The email of the existing user to run the action as}
        {--reason= : Why the user is deactivated; stored on the account}
        {--dry-run : Resolve both users and run the action authorization check, writing nothing}
        {--json : Print user, outcome and sessionsEnded as JSON}';

    public $description = 'Deactivate a user, keeping their roles and history. Runs as the --as user.';

    protected ?int $sessionsEnded = null;

    public function handle(): int
    {
        $this->sessionsEnded = null;

        // The action names the sessions it ended on the event it dispatches
        // after commit, so the command reports the action's own number rather
        // than counting session rows it never wrote.
        Event::listen(UserDeactivated::class, function (UserDeactivated $event) {
            $this->sessionsEnded = $event->sessionsEnded;
        });

        try {
            ConciergeModels::assertModeSupported();
            ConciergeModels::assertAppWide("concierge:deactivate is app-wide only; in workspace mode deactivate the user through your own AccountPolicy, or set 'mode' => 'app' in config/concierge.php");

            $actor = $this->resolve((string) $this->option('as'), '--as');
            $user = $this->resolve((string) $this->argument('email'), 'email');
            $action = app(DeactivateUser::class);

            if ($this->option('dry-run')) {
                $action->authorize($actor, $user, app(AppScope::class)->account($user));
            } else {
                $action->handle($actor, $user, $this->option('reason') ?: null);
            }
        } catch (AuthorizationException $exception) {
            return $this->refuse($exception->getMessage(), (string) $exception->getCode());
        } catch (CommandRefused $refused) {
            return $this->refuse($refused->getMessage(), $refused->errorCode);
        } catch (UnsupportedConfiguration $exception) {
            return $this->refuse($exception->getMessage(), 'unsupported_configuration');
        } catch (QueryException $exception) {
            return $this->refuse(
                $exception->getMessage().' Check database.default in config/database.php and run php artisan migrate before retrying php artisan concierge:deactivate.',
                'database',
            );
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'user' => ['id' => (int) $user->getKey(), 'email' => $user->conciergeEmail()],
                'outcome' => $this->option('dry-run') ? 'would_deactivate' : 'deactivated',
                'sessionsEnded' => $this->sessionsEnded,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $sessions = $this->sessionsEnded === null
            ? 'no database sessions'
            : "{$this->sessionsEnded} session(s) ended";

        $this->line("{$user->conciergeEmail()}: ".str_replace('_', ' ', $this->option('dry-run') ? 'would_deactivate' : 'deactivated').", {$sessions}");

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
                    ? 'concierge:deactivate needs --as=<email> naming the existing user to run the action as.'
                    : 'concierge:deactivate needs the email of the user to deactivate.',
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
