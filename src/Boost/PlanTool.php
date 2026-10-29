<?php

namespace Tey\Concierge\Boost;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
/** @internal The concierge:install dry-run plan. Setting up stays a reviewed php artisan command. */
final class PlanTool extends Tool
{
    protected string $name = 'concierge-plan';

    protected string $description = 'Return the concierge:install --dry-run --json plan: what was detected, the choices, and each step with its status. Optionally pass mode and workspace_model as the command\'s flags would. This tool writes nothing; to set up, run php artisan concierge:install.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()->enum(['app', 'workspaces'])->description('As --mode: app or workspaces.'),
            'workspace_model' => $schema->string()->description('As --workspace-model: the model to use as workspaces.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $options = array_filter([
            '--mode' => $request->get('mode'),
            '--workspace-model' => $request->get('workspace_model'),
        ], fn ($value) => is_string($value) && $value !== '');

        return Response::json(InstallPlan::read($options));
    }
}
