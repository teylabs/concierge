<?php

namespace Tey\Concierge\Http;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Contracts\PageResponder;
use Tey\Concierge\Data\Props;

/**
 * Renders with Inertia when the app has it. Concierge does not require
 * Inertia, so without it the props are returned as JSON, with the component
 * named in the X-Concierge-Page header.
 */
class InertiaPageResponder implements PageResponder
{
    public const string INERTIA = 'Inertia\\Inertia';

    public const string HEADER = 'X-Concierge-Page';

    public function render(Request $request, string $component, Props $props): Responsable|Response
    {
        if (class_exists(self::INERTIA)) {
            /** @var Responsable */
            return call_user_func([self::INERTIA, 'render'], $component, $props->toArray());
        }

        return response()->json($props->toArray(), headers: [self::HEADER => $component]);
    }
}
