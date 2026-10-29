<?php

namespace Tey\Concierge\Boost;

use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Tey\Concierge\ConciergeServiceProvider;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Enums\WorkspaceEngine;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Roles;
use Throwable;

#[IsReadOnly]
/**
 * @internal Whether concierge is set up, how, and what concierge:install would
 * still do. Owners are counted everywhere; their emails are listed only in the
 * local environment, so a connected agent learns no member's address elsewhere.
 */
final class StatusTool extends Tool
{
    protected string $name = 'concierge-status';

    protected string $description = 'Read concierge\'s setup state: whether it is set up, the mode and workspace engine, the user and workspace models and their key types, the configured roles, the owners (emails only in the local environment), and the concierge:install steps not yet in place. This tool writes nothing.';

    public function handle(Request $request): Response
    {
        $setUp = ConciergeServiceProvider::setUp();
        $plan = InstallPlan::read();

        return Response::json([
            'set_up' => $setUp,
            'mode' => $setUp ? Mode::configured()->value : null,
            'engine' => $setUp && Mode::configured() === Mode::Workspaces ? WorkspaceEngine::configured()->value : null,
            'models' => [
                'user' => $this->model(config('concierge.models.user')),
                'workspace' => $this->model(config('concierge.models.workspace')),
            ],
            'roles' => $setUp ? Roles::configured() : [],
            'owners' => $setUp ? $this->owners() : null,
            'pending' => array_values(array_filter(
                (array) ($plan['steps'] ?? []),
                fn (array $step) => ! in_array($step['status'], ['already', 'info'], true),
            )),
        ]);
    }

    /** @return array{class: string, key: string}|null */
    private function model(mixed $class): ?array
    {
        if (! is_string($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        $model = new $class;

        return ['class' => $class, 'key' => $model->getIncrementing() && $model->getKeyType() === 'int' ? 'int' : $model->getKeyType()];
    }

    /**
     * App-wide owners hold the owner role; a workspace's owner is its ownership
     * row. The kit engine keeps owners in the kit's teams, so it reports null.
     *
     * @return array{count: int, emails: list<string>|null}|null
     */
    private function owners(): ?array
    {
        try {
            $users = match (true) {
                Mode::configured() === Mode::App => Roles::find((string) config('concierge.owner_role', 'owner'))?->users()->get() ?? collect(),
                WorkspaceEngine::configured() === WorkspaceEngine::Concierge => ConciergeModels::ownership()::query()->with('membership.user')->get()->map(fn ($ownership) => $ownership->membership->user),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        if ($users === null) {
            return null;
        }

        return [
            'count' => $users->filter()->count(),
            'emails' => app()->environment('local')
                ? $users->map(fn ($user) => $user instanceof Member ? $user->conciergeEmail() : null)->filter()->unique()->values()->all()
                : null,
        ];
    }
}
