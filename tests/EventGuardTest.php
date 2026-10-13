<?php

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every event dispatches after the surrounding transaction commits, so a listener never sees,
 * or emails about, a change that was rolled back, and carries only ids and plain values, so
 * queued listeners serialise cheaply and read fresh state. Classes are found by reflection over
 * the files in src/Events, so a new event is covered without registering it. No event needs an
 * exception today.
 */
const EVENT_EXCEPTIONS = [];

/**
 * @return list<string> fully-qualified class names of every event in src/Events
 */
function conciergeEventClasses(): array
{
    $root = dirname(__DIR__);

    if (! is_dir("$root/src/Events")) {
        return [];
    }

    $sourceRoot = "$root/src";
    $classes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$sourceRoot/Events", FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($sourceRoot) + 1));
        $class = 'Tey\\Concierge\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

        if (class_exists($class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * @return list<string> how the event breaks the rules; empty when it follows them
 */
function conciergeEventViolations(string $event): array
{
    $violations = [];
    $reflection = new ReflectionClass($event);

    if (! $reflection->implementsInterface(ShouldDispatchAfterCommit::class)) {
        $violations[] = "$event must implement ShouldDispatchAfterCommit so a listener never sees a rolled-back change.";
    }

    if (! in_array(Dispatchable::class, class_uses_recursive($event), true)) {
        $violations[] = "$event must use Dispatchable so the event stays dispatchable.";
    }

    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->getDeclaringClass()->getName() !== $event) {
            continue;
        }

        $type = $property->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : 'untyped';

        if (! $type instanceof ReflectionNamedType || ! in_array($name, ['int', 'string', 'bool', 'array'], true)) {
            $violations[] = "$event::\${$property->getName()} must be typed int, string, bool or array, not $name.";
        }
    }

    return $violations;
}

it('guards that every concierge event dispatches after commit and carries only ids', function () {
    $events = conciergeEventClasses();

    expect($events)->not->toBeEmpty();

    $offenders = [];
    $excepted = [];

    foreach ($events as $event) {
        $violations = conciergeEventViolations($event);

        if (! array_key_exists($event, EVENT_EXCEPTIONS)) {
            array_push($offenders, ...$violations);

            continue;
        }

        if (trim(EVENT_EXCEPTIONS[$event]) === '') {
            $offenders[] = "$event is allow-listed without a reason.";
        }

        if ($violations !== []) {
            $excepted[$event] = EVENT_EXCEPTIONS[$event];
        }
    }

    expect($offenders)->toBe([])
        ->and($excepted)->toBe(EVENT_EXCEPTIONS, 'Remove stale event allow-list entries.');
});
