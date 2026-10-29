<?php

use Illuminate\Support\Facades\Artisan;
use Tey\Concierge\Tests\TestCase;

uses(TestCase::class);

/**
 * A reviewed prompt site maps command => declaring class::method => option => reason.
 * Every option must exist on the command and answer that method's question;
 * the method must honour --no-interaction.
 * Delegated prompts in mod are disabled by PagesCommand's --no-interaction.
 */
const COMMAND_PROMPT_OPTIONS = [
    'concierge:install' => [
        'Tey\\Concierge\\Commands\\InstallCommand::choices' => [
            'workspace-model' => 'Answers whether to adopt the team model it found.',
            'mode' => 'Answers where invitations and roles apply.',
            'local-login' => 'Answers whether to turn on local login when the app has no sign-in.',
        ],
    ],
];

it('gives every registered concierge command JSON and a flag for every prompt', function () {
    $commands = array_filter(Artisan::all(), fn ($command, $name) => str_starts_with($name, 'concierge:'), ARRAY_FILTER_USE_BOTH);
    expect($commands)->not->toBeEmpty()
        ->and(Artisan::all())->not->toHaveKey('concierge');

    $offenders = [];
    $reviewed = [];

    foreach ($commands as $name => $command) {
        if (! $command->getDefinition()->hasOption('json')) {
            $offenders[] = "$name lacks --json";
        }

        foreach ((new ReflectionClass($command))->getMethods() as $method) {
            $path = $method->getFileName();
            if (! $path || ! str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', dirname(__DIR__)).'/src/')) {
                continue;
            }

            $source = implode('', array_slice(file($path), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            $tokens = array_values(array_filter(token_get_all('<?php '.$source), fn ($token) => ! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)));
            $prompts = [];

            foreach ($tokens as $index => $token) {
                if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ($tokens[$index + 1] ?? null) === '(') {
                    $function = basename(str_replace('\\', '/', $token[1]));
                    if (in_array($function, ['confirm', 'ask', 'choice', 'text', 'select', 'multiselect', 'search'], true)) {
                        $prompts[] = $function;
                    }
                }
            }

            if ($prompts === []) {
                continue;
            }

            $site = $method->getDeclaringClass()->getName().'::'.$method->getName();
            $options = COMMAND_PROMPT_OPTIONS[$name][$site] ?? [];
            $reviewed[$name][$site] = $options;
            if (count($options) < count($prompts)) {
                $offenders[] = "$name $site has an unpaired prompt; add a flag and document its pairing in COMMAND_PROMPT_OPTIONS";
            }
            foreach ($options as $option => $reason) {
                if (! $command->getDefinition()->hasOption($option) || trim($reason) === '') {
                    $offenders[] = "$name $site needs --$option and a reason";
                }
            }
        }
    }

    expect($offenders)->toBe([])
        ->and($reviewed)->toBe(COMMAND_PROMPT_OPTIONS, 'Remove stale prompt allow-list entries.');
});
