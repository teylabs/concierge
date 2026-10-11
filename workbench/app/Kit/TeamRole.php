<?php

namespace Workbench\App\Kit;

/**
 * The starter kit's App\Enums\TeamRole, trimmed to what concierge reads.
 */
enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}
