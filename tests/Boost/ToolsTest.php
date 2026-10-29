<?php

use Laravel\Boost\Mcp\Boost;
use Laravel\Boost\Mcp\ToolRegistry;
use Laravel\Boost\Mcp\Tools\ApplicationInfo;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Tey\Concierge\Boost\PlanTool;
use Tey\Concierge\Boost\StatusTool;
use Tey\Concierge\Boost\ToolRegistrar;
use Workbench\App\Models\User;

function tool_data(Tool $tool, array $arguments = []): array
{
    $response = $tool->handle(new Request($arguments));
    assert($response instanceof Response);
    expect($response->isError())->toBeFalse((string) $response->content());

    return json_decode($response->content()->toTool($tool)['text'], true, flags: JSON_THROW_ON_ERROR);
}

it('registers two read-only tools alongside existing Boost includes', function () {
    config()->set('boost.mcp.tools.include', [ApplicationInfo::class]);
    ToolRegistrar::register(app());
    ToolRegistrar::register(app());

    expect(config('boost.mcp.tools.include'))->toBe([ApplicationInfo::class, StatusTool::class, PlanTool::class]);

    ToolRegistry::clearCache();
    foreach ([StatusTool::class => 'concierge-status', PlanTool::class => 'concierge-plan'] as $class => $name) {
        $tool = app($class)->toArray();
        expect(ToolRegistry::isToolAllowed($class))->toBeTrue()
            ->and($tool['name'])->toBe($name)
            ->and($tool['annotations']['readOnlyHint'])->toBeTrue();
    }

    $server = (new ReflectionClass(Boost::class))->newInstanceWithoutConstructor();
    expect((new ReflectionMethod($server, 'discoverTools'))->invoke($server))->toContain(StatusTool::class, PlanTool::class);
    ToolRegistry::clearCache();
});

it('reports a set-up app with pinned keys', function () {
    $owner = host_user('owner');
    host_workspace('Acme', $owner);

    $data = tool_data(app(StatusTool::class));

    expect(array_keys($data))->toBe(['set_up', 'mode', 'engine', 'models', 'roles', 'owners', 'pending'])
        ->and($data['set_up'])->toBeTrue()
        ->and($data['mode'])->toBe('workspaces')
        ->and($data['engine'])->toBe('concierge')
        ->and($data['models']['user'])->toBe(['class' => User::class, 'key' => 'int'])
        ->and($data['roles'])->toContain('owner')
        ->and($data['owners'])->toBe(['count' => 1, 'emails' => null]);
});

it('lists owner emails only in the local environment', function () {
    $owner = host_user('owner');
    host_workspace('Acme', $owner);
    app()->detectEnvironment(fn () => 'local');

    try {
        expect(tool_data(app(StatusTool::class))['owners'])->toBe(['count' => 1, 'emails' => [$owner->email]]);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('returns the install plan and writes nothing', function () {
    $before = file_exists(config_path('concierge.php'));

    $data = tool_data(app(PlanTool::class), ['mode' => 'app']);

    expect(array_keys($data))->toBe(['detected', 'choices', 'steps', 'set_up'])
        ->and(collect($data['steps'])->pluck('status')->unique()->diff(['already', 'would', 'manual', 'info', 'blocked'])->all())->toBe([])
        ->and(file_exists(config_path('concierge.php')))->toBe($before);
});
