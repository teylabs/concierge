<?php

namespace Workbench\App\Providers;

use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\FlatModuleWriter;

/**
 * Development-only generator for resources/js/types.ts. Not registered by the package provider.
 */
class TypeScriptTransformerServiceProvider extends TypeScriptTransformerApplicationServiceProvider
{
    /** Overridden by the drift test to generate into a scratch directory. */
    public static ?string $outputDirectory = null;

    protected function configure(TypeScriptTransformerConfigFactory $config): void
    {
        $config
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->transformDirectories(dirname(__DIR__, 3).'/src/Data/UI')
            ->outputDirectory(static::$outputDirectory ?? dirname(__DIR__, 3).'/resources/js')
            ->writer(new FlatModuleWriter('types.ts'))
            ->withoutManifest();
    }
}
