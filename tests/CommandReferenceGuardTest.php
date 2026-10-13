<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Concierge\Tests\TestCase;

uses(TestCase::class);

/**
 * The README command reference must match registered concierge:* commands and
 * their option tables, excluding arguments and Laravel's standard options.
 * Commands that write need --dry-run; exceptions map command => one-line reason.
 * No commands need an exception today; stale exceptions must be removed.
 */
const COMMAND_REFERENCE_ALLOWED_NO_DRY_RUN = [];

const COMMAND_REFERENCE_WRITING_COMMANDS = [
    'concierge:sync-roles', // Creates global role definitions.
    'concierge:owners', // Creates users, grants ownership and sends password links.
    'concierge:import-members', // Writes workspace memberships and ownership.
    'concierge:pages', // Writes Inertia wrapper pages into the app.
];

it('keeps the README command reference in step with registered commands and options', function () {
    $commands = array_filter(Artisan::all(), fn ($command, $name) => str_starts_with($name, 'concierge:'), ARRAY_FILTER_USE_BOTH);
    expect($commands)->not->toBeEmpty();

    $readme = file_get_contents(dirname(__DIR__).'/README.md');
    expect(preg_match('/^## Command reference\h*\R(.*?)(?=^## |\z)/ms', $readme, $section))
        ->toBe(1, 'README.md needs a Command reference section.');

    preg_match_all('/^### `(?<name>concierge:[^\s`]+)(?: [^`]*)?`\h*\R(?<body>.*?)(?=^#{1,3} |\z)/ms', $section[1], $headings, PREG_SET_ORDER);

    $documented = [];
    foreach ($headings as $heading) {
        preg_match_all('/^\h*\|\h*`--([^\s=`]+)(?:=[^`]*)?`\h*\|/m', $heading['body'], $options);
        $documented[$heading['name']] = $options[1];
    }

    $standardOptions = ['help', 'quiet', 'silent', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'];
    $offenders = [];
    $reviewed = [];

    foreach (array_diff(array_keys($documented), array_keys($commands)) as $name) {
        $offenders[] = "$name has a README heading but is not registered";
    }

    foreach ($commands as $name => $command) {
        if (! array_key_exists($name, $documented)) {
            $offenders[] = "$name lacks a README command reference heading";
        }

        $defined = array_diff(array_keys($command->getDefinition()->getOptions()), $standardOptions);
        $listed = array_diff($documented[$name] ?? [], $standardOptions);

        foreach (array_diff($defined, $listed) as $option) {
            $offenders[] = "$name is missing --$option from its README option table";
        }
        foreach (array_diff($listed, $defined) as $option) {
            $offenders[] = "$name documents unknown --$option in its README option table";
        }

        if (in_array($name, COMMAND_REFERENCE_WRITING_COMMANDS, true) && ! $command->getDefinition()->hasOption('dry-run')) {
            if (array_key_exists($name, COMMAND_REFERENCE_ALLOWED_NO_DRY_RUN)) {
                $reason = COMMAND_REFERENCE_ALLOWED_NO_DRY_RUN[$name];
                $reviewed[$name] = $reason;
                if (trim($reason) === '') {
                    $offenders[] = "$name needs a reason for its --dry-run exception";
                }
            } else {
                $offenders[] = "$name writes but lacks --dry-run";
            }
        }
    }

    foreach (array_diff(COMMAND_REFERENCE_WRITING_COMMANDS, array_keys($commands)) as $name) {
        $offenders[] = "$name is not registered; remove its stale writing-command entry";
    }

    expect($offenders)->toBe([])
        ->and($reviewed)->toBe(COMMAND_REFERENCE_ALLOWED_NO_DRY_RUN, 'Remove stale --dry-run allow-list entries.');
});
