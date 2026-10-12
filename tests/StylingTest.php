<?php

/**
 * Styling is pure Tailwind: utilities in the markup, tokens in CSS variables.
 * Each entry is a path relative to the repository root, with the reason it may exist.
 */
const STYLING_ALLOWED_CSS = [
    'resources/css/concierge.css' => 'Defines the --concierge-* tokens and the Tailwind @source; holds no rules.',
    'harness/harness.css' => 'Tailwind entry for the browser harness: imports only.',
    'tests/Consumer/host/resources/css/app.css' => 'Fixture host app: imports Concierge and overrides one token.',
];

/** Same format as above for `style=` / `:style=` bindings in resources/js .vue files. */
const STYLING_ALLOWED_STYLE_BINDINGS = [];

/**
 * @return list<string> repository-relative paths
 */
function stylingFiles(string $dir, array $extensions): array
{
    $root = dirname(__DIR__);

    if (! is_dir("$root/$dir")) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    sort($files);

    return $files;
}

it('has no .css file outside the allow-list', function () {
    $root = dirname(__DIR__);
    $found = [];

    foreach (['resources', 'harness', 'src', 'stubs', 'workbench', 'tests'] as $dir) {
        $found = [...$found, ...stylingFiles($dir, ['css'])];
    }

    expect(array_values(array_diff($found, array_keys(STYLING_ALLOWED_CSS))))->toBe([]);

    foreach (array_keys(STYLING_ALLOWED_CSS) as $path) {
        expect(file_exists("$root/$path"))->toBeTrue("Allow-listed $path no longer exists; remove it.");
    }
});

it('has no <style> block in resources/js or harness', function () {
    $offenders = [];

    foreach ([...stylingFiles('resources/js', ['vue', 'html']), ...stylingFiles('harness', ['vue', 'html'])] as $path) {
        if (preg_match('/<style[\s>]/i', file_get_contents(dirname(__DIR__)."/$path"))) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

it('has no style binding in resources/js .vue files outside the allow-list', function () {
    $offenders = [];

    foreach (stylingFiles('resources/js', ['vue']) as $path) {
        if (array_key_exists($path, STYLING_ALLOWED_STYLE_BINDINGS)) {
            continue;
        }

        if (preg_match('/(?<![\w-]):?\bstyle\s*=/i', file_get_contents(dirname(__DIR__)."/$path"))) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});
