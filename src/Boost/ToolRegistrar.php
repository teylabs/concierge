<?php

namespace Tey\Concierge\Boost;

use Illuminate\Contracts\Foundation\Application;
use Laravel\Boost\Mcp\Boost;
use Laravel\Mcp\Server\Tool;

/**
 * Adds concierge's read-only tools to Laravel Boost's MCP server when Boost is
 * installed. No MCP class loads without it. Registered before setup too, so an
 * agent can ask whether concierge is set up.
 */
final class ToolRegistrar
{
    public const TOOLS = [StatusTool::class, PlanTool::class];

    public static function register(Application $app): void
    {
        if (! class_exists(Boost::class) || ! class_exists(Tool::class)) {
            return;
        }

        $config = $app->make('config');
        $includes = (array) $config->get('boost.mcp.tools.include', []);

        foreach (self::TOOLS as $tool) {
            if (! in_array($tool, $includes, true)) {
                $includes[] = $tool;
            }
        }

        $config->set('boost.mcp.tools.include', array_values($includes));
    }
}
