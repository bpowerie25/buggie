<?php

namespace App\Support\Chat;

use RuntimeException;

/**
 * The address is not one we are willing to call.
 *
 * Its own type because it is the one failure that must not be retried: waiting an
 * hour will not move 169.254.169.254 onto the public internet.
 */
class UnsafeChatUrl extends RuntimeException {}
