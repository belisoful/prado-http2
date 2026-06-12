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

	public function testMissingLibraryThrows()
	{
		TNgHttp2::setLibraryPath('/nonexistent/libnghttp2.dylib');
		try {
			$this->expectException(THttp2Exception::class);
			TNgHttp2::ffi();
		} finally {
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
}
