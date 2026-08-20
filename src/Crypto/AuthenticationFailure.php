<?php

declare(strict_types=1);

namespace Drupal\strata\Crypto;

use RuntimeException;

/**
 * A sealed value did not authenticate.
 *
 * Its own type because it is the one cipher failure a caller acts on differently. Three things
 * produce it - the key changed, the bytes changed, or the value was moved to a key it was not
 * sealed under - and none of them are fixed by fetching the same object again, so a verify pass
 * reports it as tampering rather than as a transient read error. Classifying it by matching the
 * exception message would break the moment the wording changed.
 *
 * @see CipherInterface::open()
 */
final class AuthenticationFailure extends RuntimeException {}
