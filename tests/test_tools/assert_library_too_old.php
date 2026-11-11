<?php

/**
 * CI check for a host whose only libnghttp2 predates TNgHttp2::MIN_VERSION (Ubuntu 24.04 ships
 * 1.59.0).  The binding must report the library as too old, every time it is asked, without
 * crashing PHP.  Exits 0 when it does, 1 otherwise.
 *
 * Run it only where the installed library is known to be too old.
 */

use Prado\IO\Http2\THttp2Exception;
use Prado\IO\Http2\TNgHttp2;

require_once __DIR__ . '/phpunit_bootstrap.php';

$failures = [];
for ($i = 0; $i < 20; $i++) {
	if (TNgHttp2::isAvailable()) {
		$failures[] = "call $i: isAvailable() is true (libnghttp2 " . TNgHttp2::version() . ' loaded)';
		break;
	}
	try {
		TNgHttp2::ffi();
		$failures[] = "call $i: ffi() did not throw";
	} catch (THttp2Exception $e) {
		if ($e->getErrorCode() !== 'http2_library_too_old') {
			$failures[] = "call $i: " . $e->getErrorCode() . ': ' . $e->getMessage();
		} elseif (!str_contains($e->getMessage(), TNgHttp2::MIN_VERSION)) {
			$failures[] = "call $i: the message does not name " . TNgHttp2::MIN_VERSION . ': ' . $e->getMessage();
		} elseif ($i === 0) {
			echo $e->getMessage(), PHP_EOL;
		}
	}
	gc_collect_cycles();
}
if ($failures !== []) {
	fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
	exit(1);
}
echo 'http2_library_too_old reported 20 times.', PHP_EOL;
