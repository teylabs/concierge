<?php

namespace Tey\Concierge\Contracts;

interface RecentAuthentication
{
    public function confirmed(Member $user): bool;
}
