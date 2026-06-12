<?php
/**
 * Common settings for all unit tests of the PRADO HTTP/2 extension.
 *
 * Registers the extension's error message file so exception codes resolve, then autoloads the
 * framework and the extension via Composer's PSR-4 map.
 */

require_once(__DIR__ . '/../../vendor/autoload.php');

// Tests run without a TApplication, so register the extension's error file the way the bootstrap
// module would: via TPluginModule's auto-discovery (errorMessages.txt beside THttp2Module).
if ($errorFile = (new \Prado\IO\Http2\THttp2Module())->getErrorFile()) {
	\Prado\Exceptions\TException::addMessageFile($errorFile);
}
