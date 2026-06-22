<?php

/**
 * Common settings for all unit tests of the PRADO HTTP/2 extension.
 *
 * Registers the extension's error message file so exception codes resolve, then autoloads the
 * framework and the extension via Composer's PSR-4 map.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Tests run without a TApplication, so register the extension's error file directly, the way the
// composer.json `extra.prado.error-messages` entry does for a real PRADO application.
\Prado\Exceptions\TException::addMessageFile(dirname(__DIR__, 2) . '/config/errorMessages.txt');
