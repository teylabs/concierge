<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

/**
 * What a login page needs to show the one-click local button: where to POST
 * (decided by the server) and who it signs in as. Hosts pass it as `localLogin`,
 * null in every environment where local login is not available.
 */
class LocalLoginOffer extends Data
{
    public function __construct(
        public string $url,
        public string $email,
    ) {}
}
