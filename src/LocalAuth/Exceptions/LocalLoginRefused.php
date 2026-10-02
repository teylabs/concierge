<?php

namespace Tey\Concierge\LocalAuth\Exceptions;

use RuntimeException;

/**
 * The click cannot sign anyone in, and says why in words a developer can act on.
 * Only ever raised (and shown) in the local environment.
 */
class LocalLoginRefused extends RuntimeException {}
