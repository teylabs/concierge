<?php

namespace Workbench\App\TypeScript;

use Spatie\LaravelTypeScriptTransformer\Transformers\LaravelAttributedClassTransformer;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpPropertyNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;

/**
 * Page props are readonly PHP classes, but the TypeScript contract has never
 * marked their fields readonly, so leave that modifier out.
 */
class PropsTransformer extends LaravelAttributedClassTransformer
{
    protected function isPropertyReadonly(PhpPropertyNode $phpPropertyNode, PhpClassNode $phpClassNode, TypeScriptNode $type): bool
    {
        return false;
    }
}
