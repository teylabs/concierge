<?php

namespace Workbench\App\TypeScript;

use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptGeneric;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;

/**
 * The two paginated collection aliases the laravel-data extension used to emit.
 * resources/js/types.ts is re-exported by the package, so they stay part of the
 * published contract until they are dropped on purpose.
 */
class PaginatedCollectionTypes implements TransformedProvider
{
    public function provide(): array
    {
        return [
            $this->alias('PaginatedDataCollection', LengthAwarePaginator::class),
            $this->alias('CursorPaginatedDataCollection', CursorPaginator::class),
        ];
    }

    /**
     * @param  class-string  $paginator
     */
    protected function alias(string $name, string $paginator): Transformed
    {
        $generics = [new TypeScriptIdentifier('TKey'), new TypeScriptIdentifier('TValue')];

        return new Transformed(
            new TypeScriptAlias(
                new TypeScriptGeneric(new TypeScriptIdentifier($name), $generics),
                new TypeScriptGeneric(new TypeScriptReference(new ClassStringReference($paginator)), $generics),
            ),
            new ClassStringReference('Spatie\\LaravelData\\'.$name),
            ['Spatie', 'LaravelData'],
            true,
        );
    }
}
