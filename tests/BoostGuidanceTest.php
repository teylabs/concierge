<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Yaml\Yaml;
use Tey\Concierge\Tests\TestCase;

uses(TestCase::class);

/*
 * The Laravel Boost guideline and skill in resources/boost: they parse, and the
 * skill names every concierge:* command with each of its options, every public
 * action and every event, so new surface can't ship without guidance.
 */

function boost_file(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__).'/resources/boost/'.$path);
}

function boost_mentions(string $text, string $token): bool
{
    return preg_match('/(?<![a-zA-Z0-9_.:\/-])'.preg_quote($token, '/').'(?![a-zA-Z0-9_\/-])/', $text) === 1;
}

/** @return list<string> class basenames of the PHP files in a src directory */
function boost_classes(string $directory): array
{
    return array_map(fn (string $path) => basename($path, '.php'), glob(dirname(__DIR__)."/src/{$directory}/*.php") ?: []);
}

it('ships a guideline that renders and a skill with frontmatter', function () {
    expect(Blade::render(boost_file('guidelines/core.blade.php')))->toContain('concierge:install');

    [, $frontmatter] = explode('---', boost_file('skills/concierge-development/SKILL.md'), 3);
    $meta = Yaml::parse($frontmatter);

    expect($meta['name'])->toBe('concierge-development')
        ->and($meta['description'])->toBeString()->not->toBeEmpty();
});

it('covers every command and option', function () {
    $skill = boost_file('skills/concierge-development/SKILL.md');
    $lines = preg_split('/\n|(?=\bconcierge:)/', $skill) ?: [];
    $standard = ['help', 'quiet', 'silent', 'verbose', 'version', 'ansi', 'no-interaction', 'env'];
    $missing = [];

    foreach (Artisan::all() as $name => $command) {
        if (! str_starts_with($name, 'concierge:')) {
            continue;
        }

        if (! boost_mentions($skill, $name)) {
            $missing[] = $name;
        }

        foreach ($command->getDefinition()->getOptions() as $option) {
            if (in_array($option->getName(), $standard, true)) {
                continue;
            }

            $flag = '--'.$option->getName();
            $covered = in_array($option->getName(), ['json', 'dry-run'], true) && str_contains($skill, "Commands that write take `--dry-run`");

            foreach ($lines as $line) {
                $covered = $covered || (boost_mentions($line, $name) && boost_mentions($line, $flag));
            }

            if (! $covered) {
                $missing[] = "{$name} {$flag}";
            }
        }
    }

    expect($missing)->toBe([], 'Add these to resources/boost/skills/concierge-development/SKILL.md: '.implode(', ', $missing));
});

it('covers every action and event', function () {
    $skill = boost_file('skills/concierge-development/SKILL.md');
    $missing = array_values(array_filter(
        [...boost_classes('Actions'), ...boost_classes('Events')],
        fn (string $class) => ! boost_mentions($skill, $class),
    ));

    expect($missing)->toBe([], 'Add these to resources/boost/skills/concierge-development/SKILL.md: '.implode(', ', $missing));
});

it('does not let one command cover another command\'s option', function () {
    $lines = preg_split('/\n|(?=\bconcierge:)/', 'concierge:invite --role, concierge:owners') ?: [];

    expect(collect($lines)->contains(fn ($line) => boost_mentions($line, 'concierge:owners') && boost_mentions($line, '--role')))->toBeFalse();
});
