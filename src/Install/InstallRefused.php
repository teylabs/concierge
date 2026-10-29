<?php

namespace Tey\Concierge\Install;

use RuntimeException;

/** An answer or flag concierge:install can't act on; the message says what to pass. */
class InstallRefused extends RuntimeException {}
