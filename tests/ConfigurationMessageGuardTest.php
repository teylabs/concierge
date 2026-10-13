<?php

/**
 * Every UnsupportedConfiguration message must name a fix: a config key
 * (concierge.*, permission.* or auth.*), a file under config/, a php artisan
 * command, or a Concierge:: API call. Messages that only describe the problem
 * leave an agent or a CI log with no next step.
 *
 * Each entry maps a source location (relative path:line) to a one-line reason
 * why it is allowed to name no fix. The list starts empty; a stale entry fails.
 */
const CONFIGURATION_MESSAGE_EXCEPTIONS = [];

/**
 * @return list<string> repository-relative paths
 */
function configurationSourceFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/src", FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }

    sort($files);

    return $files;
}

/**
 * Collect the string pieces of the first argument to the constructor call that
 * begins at the given open-paren index. Handles nested calls (sprintf), string
 * concatenation and interpolation by reading tokens, not lines.
 */
function extractConfigurationMessage(array $tokens, int $openParenIndex): string
{
    $depth = 1;
    $count = count($tokens);
    $pieces = [];

    for ($i = $openParenIndex + 1; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token === '(') {
            $depth++;

            continue;
        }

        if ($token === ')') {
            $depth--;
            if ($depth === 0) {
                break;
            }

            continue;
        }

        if ($token === ',' && $depth === 1) {
            break;
        }

        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $pieces[] = $token[1];
        }
    }

    return implode('', $pieces);
}

function configurationMessageNamesFix(string $message): bool
{
    $indicators = [
        'concierge.',
        'permission.',
        'auth.',
        'config/',
        'php artisan',
        'Concierge::',
    ];

    foreach ($indicators as $indicator) {
        if (str_contains($message, $indicator)) {
            return true;
        }
    }

    return false;
}

it('names a fix in every UnsupportedConfiguration message', function () {
    $root = dirname(__DIR__);
    $offenders = [];
    $reviewed = [];

    foreach (configurationSourceFiles($root) as $path) {
        $tokens = token_get_all(file_get_contents("$root/$path"));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW) {
                continue;
            }

            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;
            }

            if ($j >= $count || ! is_array($tokens[$j]) || ! in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            if (basename(str_replace('\\', '/', $tokens[$j][1])) !== 'UnsupportedConfiguration') {
                continue;
            }

            $k = $j + 1;
            while ($k < $count && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $k++;
            }

            if ($k >= $count || $tokens[$k] !== '(') {
                continue;
            }

            $line = $tokens[$i][2] ?? 1;
            $location = "$path:$line";
            $message = extractConfigurationMessage($tokens, $k);

            if ($message === '') {
                $offenders[] = "$location has no extractable message";

                continue;
            }

            if (array_key_exists($location, CONFIGURATION_MESSAGE_EXCEPTIONS)) {
                $reviewed[$location] = true;

                continue;
            }

            if (! configurationMessageNamesFix($message)) {
                $offenders[] = "$location names no fix: $message";
            }
        }
    }

    expect($offenders)->toBe([])
        ->and(array_keys($reviewed))->toBe(array_keys(CONFIGURATION_MESSAGE_EXCEPTIONS), 'Remove stale configuration-message allow-list entries.');
});
