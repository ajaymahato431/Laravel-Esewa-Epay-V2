<?php

namespace AjayMahato\Esewa\Exceptions;

use Exception;

/**
 * Base exception for every failure raised by this package.
 *
 * Catching this type catches configuration problems, signature failures and
 * transport errors alike.
 */
class EsewaException extends Exception {}
