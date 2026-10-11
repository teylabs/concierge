<?php

use Illuminate\Support\Facades\File;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerServiceProvider as PackageTransformerServiceProvider;
use Workbench\App\Providers\TypeScriptTransformerServiceProvider;

afterEach(function () {
    TypeScriptTransformerServiceProvider::$outputDirectory = null;
});

it('keeps resources/js/types.ts in sync with the UI Data classes', function () {
    $scratch = sys_get_temp_dir().'/concierge-types-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($scratch);
    TypeScriptTransformerServiceProvider::$outputDirectory = $scratch;

    try {
        $this->app->register(PackageTransformerServiceProvider::class);
        $this->app->register(TypeScriptTransformerServiceProvider::class, force: true);
        $this->artisan('typescript:transform')->assertSuccessful();

        expect(File::get($scratch.'/types.ts'))
            ->toBe(File::get(__DIR__.'/../../../resources/js/types.ts'), 'Run `npm run types:generate` and commit resources/js/types.ts.');
    } finally {
        File::deleteDirectory($scratch);
    }
});
