<?php

namespace Tey\Concierge\Install;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What concierge:install can learn about the app without asking: its starter
 * kit and sign-in, its user and team models, and how Spatie permission is set
 * up. It only reads files, classes and config, and never writes.
 */
class AppInspector
{
    /** Model names a host's own team or organisation model commonly has. */
    public const TEAM_MODELS = ['Team', 'Organisation', 'Organization', 'Workspace'];

    public function __construct(
        protected string $basePath,
        protected string $modelsNamespace = 'App\\Models\\',
    ) {}

    /**
     * @return array{starter_kit: string, authkit: bool, kit_teams: bool, sign_in: ?string, user_model: ?string, user_key: ?string, team_model: ?string, permission: array{config_published: bool, migrations_published: bool, tables: bool, teams: bool}, concierge: array{config_published: bool}}
     */
    public function inspect(): array
    {
        $composer = $this->dependencies('composer.json', ['require', 'require-dev']);
        $npm = $this->dependencies('package.json', ['dependencies', 'devDependencies']);
        $user = config('auth.providers.users.model');
        $user = is_string($user) && class_exists($user) ? $user : null;

        return [
            'starter_kit' => match (true) {
                isset($composer['livewire/livewire']) || isset($composer['livewire/flux']) => 'livewire',
                isset($npm['@inertiajs/vue3']) => 'vue',
                isset($npm['@inertiajs/react']) => 'react',
                isset($npm['@inertiajs/svelte']) => 'svelte',
                default => 'none',
            },
            'authkit' => isset($composer['laravel/workos']),
            'kit_teams' => $this->kitTeams(),
            'sign_in' => match (true) {
                isset($composer['laravel/workos']) => 'workos',
                isset($composer['laravel/fortify']) => 'fortify',
                default => null,
            },
            'user_model' => $user,
            'user_key' => $user ? $this->keyType($user) : null,
            'team_model' => $this->teamModel(),
            'permission' => [
                'config_published' => file_exists(config_path('permission.php')),
                'migrations_published' => glob(database_path('migrations/*create_permission_tables.php')) !== [],
                'tables' => $this->hasTable(config('permission.table_names.roles', 'roles')),
                'teams' => (bool) config('permission.teams'),
            ],
            'concierge' => [
                'config_published' => file_exists(config_path('concierge.php')),
            ],
        ];
    }

    /**
     * A starter kit generated with teams has a Team model and a team_members
     * pivot migration; concierge reads those through its kit engine.
     */
    protected function kitTeams(): bool
    {
        if (! class_exists($this->modelsNamespace.'Team')) {
            return false;
        }

        foreach (glob(database_path('migrations/*.php')) ?: [] as $migration) {
            if (str_contains((string) file_get_contents($migration), "'team_members'")) {
                return true;
            }
        }

        return false;
    }

    /** @return ?class-string<Model> */
    protected function teamModel(): ?string
    {
        foreach (self::TEAM_MODELS as $name) {
            $class = $this->modelsNamespace.$name;

            if (class_exists($class) && is_a($class, Model::class, true)) {
                return $class;
            }
        }

        return null;
    }

    /** 'int' for an incrementing integer key, which concierge needs; otherwise the key type. */
    protected function keyType(string $class): string
    {
        if (! is_a($class, Model::class, true)) {
            return 'unknown';
        }

        $model = new $class;

        return $model->getIncrementing() && $model->getKeyType() === 'int' ? 'int' : $model->getKeyType();
    }

    protected function hasTable(mixed $table): bool
    {
        try {
            return is_string($table) && Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $sections
     * @return array<string, mixed>
     */
    protected function dependencies(string $file, array $sections): array
    {
        $path = $this->basePath.'/'.$file;
        $json = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;

        return array_merge(...array_map(fn (string $section) => (array) ($json[$section] ?? []), $sections));
    }
}
