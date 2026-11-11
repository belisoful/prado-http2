<?php

use Prado\IO\Http2\TNgHttp2;
use Prado\IO\Http2\THttp2Exception;

/**
 * Exercises the libnghttp2 FFI binding.  Skipped when the library is not loadable, so the
 * suite stays green on hosts without HTTP/2 support.
 */
class TNgHttp2Test extends PHPUnit\Framework\TestCase
{
	protected function setUp(): void
	{
		if (!TNgHttp2::isAvailable()) {
			$this->markTestSkipped('libnghttp2 is not available.');
		}
	}

	public function testVersionLoads()
	{
		self::assertMatchesRegularExpression('/^\d+\.\d+/', TNgHttp2::version());
	}

	public function testStrerrorResolves()
	{
		// -501 is NGHTTP2_ERR_INVALID_ARGUMENT; any valid code returns a non-empty description.
		self::assertNotSame('', TNgHttp2::strerror(-501));
	}

	public function testStrerrorHandlesNonNegativeCode()
	{
		// 0 is NGHTTP2_NO_ERROR ('Success'); strerror returns a non-empty description for non-negative codes too.
		self::assertNotSame('', TNgHttp2::strerror(0));
	}

	public function testMissingLibraryThrows()
	{
		TNgHttp2::setLibraryPath('/nonexistent/libnghttp2.dylib');
		try {
			TNgHttp2::ffi();
			self::fail('A missing library did not throw.');
		} catch (THttp2Exception $e) {
			self::assertSame('http2_library_missing', $e->getErrorCode());
			self::assertStringContainsString('/nonexistent/libnghttp2.dylib', $e->getMessage(), 'The message names what was tried.');
		} finally {
			TNgHttp2::setLibraryPath(null);
		}
	}

	public function testEnvironmentLibraryIsACandidateAheadOfThePlatformDefaults()
	{
		if (getenv('PRADO_NGHTTP2_LIB') !== false) {
			$this->markTestSkipped('PRADO_NGHTTP2_LIB is set for this run; the test would replace it.');
		}
		putenv('PRADO_NGHTTP2_LIB=/nonexistent/from-env/libnghttp2.so');
		try {
			TNgHttp2::setLibraryPath(null);                    // resolve again, now with the environment candidate
			self::assertTrue(TNgHttp2::isAvailable(), 'A bad environment path is skipped and the platform defaults still load.');
			self::assertMatchesRegularExpression('/^\d+\.\d+/', TNgHttp2::version());
			TNgHttp2::setLibraryPath('/nonexistent/explicit/libnghttp2.so');
			try {
				TNgHttp2::ffi();
				self::fail('An explicit path is the only candidate.');
			} catch (THttp2Exception $e) {
				self::assertStringNotContainsString('from-env', $e->getMessage(), 'An explicit path overrides the environment.');
			}
		} finally {
			putenv('PRADO_NGHTTP2_LIB');
			TNgHttp2::setLibraryPath(null);
		}
	}

	public function testSetLibraryPathOverridesThenRestores()
	{
		self::assertTrue(TNgHttp2::isAvailable(), 'Default resolution loads the library.');
		try {
			TNgHttp2::setLibraryPath('/nonexistent/libnghttp2.dylib');
			self::assertFalse(TNgHttp2::isAvailable(), 'An explicit bad path makes it unavailable.');
		} finally {
			TNgHttp2::setLibraryPath(null);
		}
		self::assertTrue(TNgHttp2::isAvailable(), 'Null restores default resolution.');
	}

	public function testRebindingKeepsThePreviousInstanceAlive()
	{
		// PHP caches struct field lookups per opline, keyed on the raw type pointer, and never
		// invalidates them.  A freed FFI instance lets a later instance reuse the address, and the
		// stale field descriptor is then read (a use-after-free).  No instance may ever be freed.
		$first = TNgHttp2::ffi();
		$weak = \WeakReference::create($first);
		unset($first);
		try {
			TNgHttp2::setLibraryPath('/nonexistent/libnghttp2.dylib');
			self::assertFalse(TNgHttp2::isAvailable());
			gc_collect_cycles();
			self::assertNotNull($weak->get(), 'The binding replaced by setLibraryPath() stays allocated.');
		} finally {
			TNgHttp2::setLibraryPath(null);
		}
		self::assertSame($weak->get(), TNgHttp2::ffi(), 'Resolving the same library again reuses its binding.');
	}

	public function testUnavailableLibraryIsReportedRepeatedly()
	{
		// Each isAvailable() call after a failure resolves again; the probe results are cached per
		// candidate, so the loop must neither crash nor change its answer.
		try {
			TNgHttp2::setLibraryPath('/nonexistent/libnghttp2.dylib');
			for ($i = 0; $i < 20; $i++) {
				self::assertFalse(TNgHttp2::isAvailable(), "Call $i reports the library unavailable.");
			}
		} finally {
			TNgHttp2::setLibraryPath(null);
		}
		self::assertTrue(TNgHttp2::isAvailable(), 'Default resolution still loads the library.');
		self::assertSame(TNgHttp2::version(), TNgHttp2::version(), 'The version reads back stably.');
	}

	public function testDualSessionSettingsExchangeWithConnectProtocol()
	{
		$ffi = TNgHttp2::ffi();

		$cbs = $ffi->new('nghttp2_session_callbacks*');
		$ffi->nghttp2_session_callbacks_new(\FFI::addr($cbs));

		$seenTypes = [];
		$onFrame = function ($session, $frame, $userData) use (&$seenTypes) {
			$seenTypes[] = $frame->type;
			return 0;
		};
		$ffi->nghttp2_session_callbacks_set_on_frame_recv_callback($cbs, $onFrame);

		$server = $ffi->new('nghttp2_session*');
		$client = $ffi->new('nghttp2_session*');
		$ffi->nghttp2_session_server_new(\FFI::addr($server), $cbs, null);
		$ffi->nghttp2_session_client_new(\FFI::addr($client), $cbs, null);

		// The server advertises Extended CONNECT (RFC 8441), the basis for WebSocket over HTTP/2.
		$iv = $ffi->new('nghttp2_settings_entry[1]');
		$iv[0]->settings_id = TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL;
		$iv[0]->value = 1;
		self::assertSame(0, $ffi->nghttp2_submit_settings($server, 0, $iv, 1));
		self::assertSame(0, $ffi->nghttp2_submit_settings($client, 0, null, 0));

		$pump = function ($from, $to) use ($ffi) {
			while (true) {
				$dataPtr = $ffi->new('uint8_t*');
				$n = $ffi->nghttp2_session_mem_send2($from, \FFI::addr($dataPtr));
				if ($n <= 0) {
					break;
				}
				$ffi->nghttp2_session_mem_recv2($to, $dataPtr, $n);
			}
		};
		$pump($client, $server);
		$pump($server, $client);

		self::assertContains(TNgHttp2::FRAME_SETTINGS, $seenTypes, 'SETTINGS crossed both sessions.');

		$ffi->nghttp2_session_del($server);
		$ffi->nghttp2_session_del($client);
		$ffi->nghttp2_session_callbacks_del($cbs);
	}


	public function testVersionInfoLayoutMatchesLibrary()
	{
		// nghttp2_info is declared field for field in the cdef; version_num must agree with version_str.
		$info = TNgHttp2::ffi()->nghttp2_version(0);
		self::assertSame(1, $info->age);
		[$major, $minor, $patch] = array_map('intval', explode('.', TNgHttp2::version()));
		self::assertSame(($major << 16) | ($minor << 8) | $patch, $info->version_num);
		self::assertSame('h2', \FFI::string($info->proto_str));
	}

	public function testLoadedLibraryMeetsTheMinimumVersion()
	{
		if (!TNgHttp2::isAvailable()) {
			$this->markTestSkipped('libnghttp2 is not available.');
		}
		self::assertSame('1.60.0', TNgHttp2::MIN_VERSION, 'The nghttp2_ssize API arrived in 1.60.0.');
		self::assertTrue(version_compare(TNgHttp2::version(), TNgHttp2::MIN_VERSION, '>='), 'A loaded library is at least MIN_VERSION.');
		self::assertSame(5, TNgHttp2::FRAME_PUSH_PROMISE);
		self::assertSame(-521, TNgHttp2::ERR_TEMPORAL_CALLBACK_FAILURE);
		self::assertSame(-528, TNgHttp2::ERR_PUSH_DISABLED);
	}

	public function testTooOldLibraryMessageNamesBothVersions()
	{
		$e = new \Prado\IO\Http2\THttp2Exception('http2_library_too_old', '1.59.0', '/usr/lib/libnghttp2.so.14', TNgHttp2::MIN_VERSION);
		self::assertStringContainsString('1.59.0', $e->getMessage());
		self::assertStringContainsString('1.60.0', $e->getMessage());
		self::assertStringContainsString('/usr/lib/libnghttp2.so.14', $e->getMessage());
	}
}
