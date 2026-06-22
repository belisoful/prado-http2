<?php

use Prado\IO\Http2\TH2Session;
use Prado\IO\Http2\TH2Stream;
use Prado\IO\Http2\THttp2Exception;
use Prado\IO\Http2\TNgHttp2;

/**
 * Drives a server and a client {@see TH2Session} against each other in-process (h2c, no sockets
 * or TLS), exercising the full RFC 8441 Extended CONNECT path and bidirectional DATA.
 * Skipped when libnghttp2 is unavailable.
 */
class TH2SessionTest extends PHPUnit\Framework\TestCase
{
	protected function setUp(): void
	{
		if (!TNgHttp2::isAvailable()) {
			$this->markTestSkipped('libnghttp2 is not available.');
		}
	}

	public function testExtendedConnectAndBidirectionalData()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL => 1]);
		$client->submitSettings([]);

		$serverIncoming = '';
		$clientIncoming = '';
		$status = null;
		$accepted = false;
		$serverStream = null;

		$server->attachEventHandler('onRequest', function ($session, $stream) use (&$accepted, &$serverStream, $server) {
			if ($stream->getHeader(':method') === 'CONNECT' && $stream->getHeader(':protocol') === 'websocket') {
				$server->respond($stream, [':status' => '200']);
				$serverStream = $stream;
				$accepted = true;
			}
		});
		$server->attachEventHandler('onData', function ($session, $stream) use (&$serverIncoming) {
			$serverIncoming .= $stream->getContents();
		});
		$client->attachEventHandler('onResponse', function ($session, $stream) use (&$status) {
			$status = $stream->getHeader(':status');
		});
		$client->attachEventHandler('onData', function ($session, $stream) use (&$clientIncoming) {
			$clientIncoming .= $stream->getContents();
		});

		$clientStream = $client->request([
			':method' => 'CONNECT',
			':protocol' => 'websocket',
			':scheme' => 'https',
			':path' => '/chat',
			':authority' => 'example.com',
			'sec-websocket-version' => '13',
		]);
		$clientStream->write('hello from client');

		$server->receive($client->send());        // CONNECT + DATA -> server accepts
		$client->receive($server->send());         // 200 -> client

		self::assertTrue($accepted, 'The server accepted the Extended CONNECT.');
		self::assertSame('200', $status, 'The client saw the 200 response.');
		self::assertSame('hello from client', $serverIncoming);
		self::assertInstanceOf(TH2Stream::class, $serverStream);

		$serverStream->write('world from server');
		$client->receive($server->send());         // DATA -> client
		self::assertSame('world from server', $clientIncoming);

		$server->close();
		$client->close();
	}

	public function testStreamIsADuplexStreamInterface()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL => 1]);
		$client->submitSettings([]);

		$server->attachEventHandler('onRequest', function ($session, $stream) use ($server) {
			$server->respond($stream, [':status' => '200']);
		});

		$clientStream = $client->request([
			':method' => 'CONNECT',
			':protocol' => 'websocket',
			':scheme' => 'https',
			':path' => '/',
			':authority' => 'h',
		]);
		self::assertInstanceOf(\Psr\Http\Message\StreamInterface::class, $clientStream);
		self::assertTrue($clientStream->isWritable());
		self::assertFalse($clientStream->isSeekable());
		self::assertSame('CONNECT', $clientStream->getHeader(':method'));

		$server->receive($client->send());
		$client->receive($server->send());

		// Server writes; client reads via the StreamInterface read()/getContents().
		$serverStream = $server->getStream($clientStream->getStreamId());
		$serverStream->write('payload');
		$client->receive($server->send());
		self::assertSame('payload', $clientStream->read(4096));

		$server->close();
		$client->close();
	}

	public function testFiniteResponseBodyEndsTheStream()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$server->attachEventHandler('onRequest', function ($session, $stream) use ($server) {
			$server->respond($stream, [':status' => '200', 'content-type' => 'text/plain']);
			$stream->write('hello body');
			$stream->markLocalClosed();          // flush the body, then END_STREAM
		});

		$status = null;
		$body = '';
		$closed = false;
		$client->attachEventHandler('onResponse', function ($session, $stream) use (&$status) {
			$status = $stream->getHeader(':status');
		});
		$client->attachEventHandler('onData', function ($session, $stream) use (&$body) {
			$body .= $stream->getContents();
		});
		$client->attachEventHandler('onClose', function () use (&$closed) {
			$closed = true;
		});

		$request = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$request->markLocalClosed();             // a GET has no body — end the request

		for ($i = 0; $i < 8 && !$closed; $i++) {
			$server->receive($client->send());
			$client->receive($server->send());
		}

		self::assertSame('200', $status);
		self::assertSame('hello body', $body);
		self::assertTrue($closed, 'The response stream ended (END_STREAM).');

		$server->close();
		$client->close();
	}

	public function testStreamCloseRaisesOnCloseBothSides()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$serverClosed = 0;
		$clientClosed = 0;
		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server) {
			$server->respond($stream, [':status' => '200']);
			$stream->markLocalClosed();
		});
		$server->attachEventHandler('onClose', function () use (&$serverClosed) {
			$serverClosed++;
		});
		$client->attachEventHandler('onClose', function () use (&$clientClosed) {
			$clientClosed++;
		});

		$request = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$request->markLocalClosed();
		for ($i = 0; $i < 8 && ($serverClosed === 0 || $clientClosed === 0); $i++) {
			$server->receive($client->send());
			$client->receive($server->send());
		}

		self::assertSame(1, $serverClosed, 'The server raised onClose once.');
		self::assertSame(1, $clientClosed, 'The client raised onClose once.');
		$server->close();
		$client->close();
	}

	public function testGetStreamReturnsRegisteredStreamAndNullOtherwise()
	{
		$client = new TH2Session(false);
		$client->submitSettings([]);
		self::assertNull($client->getStream(1), 'No stream yet.');
		$stream = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		self::assertSame($stream, $client->getStream($stream->getStreamId()));
		self::assertNull($client->getStream(999), 'Unknown id is null.');
		$client->close();
	}

	public function testReceiveRejectsInvalidInput()
	{
		$server = new TH2Session(true);
		$server->submitSettings([]);
		$this->expectException(THttp2Exception::class);
		$server->receive('this is not a valid HTTP/2 client connection preface');
	}

	public function testMultiplexedStreamsAreIndependent()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server) {
			$server->respond($stream, [':status' => '200']);
			$stream->write('reply' . $stream->getHeader(':path'));
			$stream->markLocalClosed();
		});
		$received = [];
		$client->attachEventHandler('onData', function ($s, $stream) use (&$received) {
			$received[$stream->getStreamId()] = ($received[$stream->getStreamId()] ?? '') . $stream->getContents();
		});

		$a = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/a']);
		$a->markLocalClosed();
		$b = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/b']);
		$b->markLocalClosed();
		self::assertNotSame($a->getStreamId(), $b->getStreamId(), 'Two streams, distinct ids.');

		for ($i = 0; $i < 8 && count($received) < 2; $i++) {
			$server->receive($client->send());
			$client->receive($server->send());
		}

		self::assertSame('reply/a', $received[$a->getStreamId()] ?? null);
		self::assertSame('reply/b', $received[$b->getStreamId()] ?? null);
		$server->close();
		$client->close();
	}

	public function testCloseIsIdempotent()
	{
		$session = new TH2Session(true);
		$session->submitSettings([]);
		$session->close();
		$session->close();             // no error on a second close
		self::assertNull($session->getStream(1));
	}

	public function testRequestHeaderBuffersAreFreedNotLeaked()
	{
		// Regression: FFI header buffers must be owned (auto-freed) after the submit, not leaked.
		// A 16 KB header padding widens the leak signal: leaking every buffer would add ~8 MB over
		// 500 iterations, so the 1 MB ceiling catches even a partial (>~12%) leak while tolerating
		// the interpreter's own steady-state noise.
		$pad = str_repeat('a', 16384);
		$once = function () use ($pad) {
			$client = new TH2Session(false);
			$client->submitSettings([]);
			$client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/', 'x-pad' => $pad]);
			$client->send();
			$client->close();
		};

		for ($i = 0; $i < 50; $i++) {  // warm up one-time allocations to steady state
			$once();
		}
		gc_collect_cycles();
		$before = memory_get_usage();
		for ($i = 0; $i < 500; $i++) {
			$once();
		}
		gc_collect_cycles();
		$growth = memory_get_usage() - $before;

		self::assertLessThan(1_000_000, $growth, 'Per-request FFI header buffers must be freed, not leaked.');
	}

	public function testGetRemoteSettingReadsPeerExtendedConnect()
	{
		// RFC 8441: a client confirms the server advertised ENABLE_CONNECT_PROTOCOL before CONNECT.
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL => 1]);
		$client->submitSettings([]);

		self::assertSame(0, $client->getRemoteSetting(TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL), 'Unknown before exchange.');
		$server->receive($client->send());
		$client->receive($server->send());
		self::assertSame(1, $client->getRemoteSetting(TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL), 'Learned after the server SETTINGS.');

		$server->close();
		$client->close();
	}

	public function testWantsIo()
	{
		$client = new TH2Session(false);
		$client->submitSettings([]);
		self::assertTrue($client->wantsIo(), 'A fresh session wants to send its preface/SETTINGS.');
		$client->close();
	}

	public function testGoawayStopsFurtherRequestsOnPeer()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		self::assertTrue($client->isRequestAllowed(), 'Requests allowed initially.');
		$server->goaway(TNgHttp2::NO_ERROR);
		$client->receive($server->send());
		$server->receive($client->send());
		self::assertFalse($client->isRequestAllowed(), 'A received GOAWAY forbids new requests.');

		$server->close();
		$client->close();
	}

	public function testResetStreamClosesItOnThePeer()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$closedStreamId = null;
		$server->attachEventHandler('onClose', function ($s, $stream) use (&$closedStreamId) {
			$closedStreamId = $stream->getStreamId();
		});

		$stream = $client->request([':method' => 'POST', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$server->receive($client->send());        // server opens the stream (onRequest)
		$stream->cancel(TNgHttp2::CANCEL);          // RST_STREAM via TH2Stream
		$server->receive($client->send());        // server sees the reset

		self::assertSame($stream->getStreamId(), $closedStreamId, 'The peer saw the stream close.');
		$server->close();
		$client->close();
	}

	public function testPingRoundTripAndOnFrameSent()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$serverSentPing = false;
		$server->attachEventHandler('onFrameSent', function ($s, $info) use (&$serverSentPing) {
			if ($info['type'] === TNgHttp2::FRAME_PING) {
				$serverSentPing = true;
			}
		});

		$client->ping('beat1234');
		$server->receive($client->send());         // server receives PING, queues a PING ACK
		$client->receive($server->send());          // ACK back to the client
		self::assertTrue($serverSentPing, 'The server sent a PING ACK (onFrameSent observed it).');

		$server->close();
		$client->close();
	}

	public function testSessionWithOptionsExchanges()
	{
		$options = new \Prado\IO\Http2\TH2Options();
		$options->setPeerMaxConcurrentStreams(10);
		$options->setNoAutoWindowUpdate(true);

		$server = new TH2Session(true, $options);     // exercises the *_new2 path
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$opened = false;
		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server, &$opened) {
			$server->respond($stream, [':status' => '200']);
			$stream->markLocalClosed();
			$opened = true;
		});
		$request = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$request->markLocalClosed();
		$server->receive($client->send());
		$client->receive($server->send());

		self::assertTrue($opened, 'A session created with options serves normally.');
		$server->close();
		$client->close();
	}

	public function testSeekThrows()
	{
		$client = new TH2Session(false);
		$stream = $client->request([':method' => 'CONNECT', ':protocol' => 'websocket', ':authority' => 'h', ':scheme' => 'https', ':path' => '/']);
		$this->expectException(\RuntimeException::class);
		try {
			$stream->seek(0);
		} finally {
			$client->close();
		}
	}

	public function testMarkLocalClosedResumesDeferredStream()
	{
		// Regression: respond() then send() defers the data provider (no body queued); a later
		// markLocalClosed() with no write() must resume the stream so END_STREAM is still emitted.
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$serverStream = null;
		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server, &$serverStream) {
			$server->respond($stream, [':status' => '204']);   // headers only; provider defers
			$serverStream = $stream;
		});
		$closed = false;
		$client->attachEventHandler('onClose', function () use (&$closed) {
			$closed = true;
		});

		$request = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$request->markLocalClosed();

		$server->receive($client->send());        // server emits HEADERS, then provideData defers DATA
		$client->receive($server->send());

		self::assertInstanceOf(TH2Stream::class, $serverStream);
		self::assertFalse($closed, 'The stream is still open while the provider is deferred.');

		$serverStream->markLocalClosed();           // finish an empty body with no preceding write()
		for ($i = 0; $i < 8 && !$closed; $i++) {
			$server->receive($client->send());
			$client->receive($server->send());
		}
		self::assertTrue($closed, 'markLocalClosed() resumed the deferred stream and ended it.');

		$server->close();
		$client->close();
	}

	public function testInformationalResponsePrecedesFinalResponse()
	{
		// RFC 9113: a 1xx informational response (e.g. 103 Early Hints) raises onInformationalResponse,
		// distinct from the final response's onResponse.
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server) {
			$server->respond($stream, [':status' => '103', 'link' => '</style.css>; rel=preload']);
			$server->respond($stream, [':status' => '200']);
			$stream->markLocalClosed();
		});

		$sequence = [];
		$client->attachEventHandler('onInformationalResponse', function ($s, $stream) use (&$sequence) {
			$sequence[] = 'info:' . $stream->getHeader(':status');
		});
		$client->attachEventHandler('onResponse', function ($s, $stream) use (&$sequence) {
			$sequence[] = 'final:' . $stream->getHeader(':status');
		});

		$request = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$request->markLocalClosed();
		for ($i = 0; $i < 8; $i++) {
			$server->receive($client->send());
			$client->receive($server->send());
		}

		self::assertSame(['info:103', 'final:200'], $sequence, 'The 1xx arrived as informational, then the final 200.');
		$server->close();
		$client->close();
	}

	public function testServerSendsTrailersClientReceivesThem()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server) {
			$server->respond($stream, [':status' => '200', 'content-type' => 'application/grpc']);
			$stream->write('payload');
			$stream->sendTrailers(['grpc-status' => '0', 'x-checksum' => 'abc123']);
		});

		$body = '';
		$trailerStatus = null;
		$sawTrailers = false;
		$sawResponse = false;
		$client->attachEventHandler('onResponse', function () use (&$sawResponse) {
			$sawResponse = true;
		});
		$client->attachEventHandler('onData', function ($s, $stream) use (&$body) {
			$body .= $stream->getContents();
		});
		$client->attachEventHandler('onTrailers', function ($s, $stream) use (&$sawTrailers, &$trailerStatus) {
			$sawTrailers = true;
			$trailerStatus = $stream->getHeader('grpc-status');
		});

		$request = $client->request([':method' => 'POST', ':scheme' => 'http', ':authority' => 'h', ':path' => '/rpc']);
		$request->markLocalClosed();
		for ($i = 0; $i < 8; $i++) {
			$server->receive($client->send());
			$client->receive($server->send());
		}

		self::assertTrue($sawResponse, 'The final response headers arrived.');
		self::assertSame('payload', $body, 'The body arrived before the trailers.');
		self::assertTrue($sawTrailers, 'The trailing header block raised onTrailers.');
		self::assertSame('0', $trailerStatus, 'The trailer value merged into the stream headers.');
		$server->close();
		$client->close();
	}

	public function testGetLocalSettingReflectsAdvertisedSetting()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL => 1]);
		$client->submitSettings([]);

		self::assertSame(0, $server->getLocalSetting(TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL), 'Not yet in effect.');
		for ($i = 0; $i < 3; $i++) {
			$client->receive($server->send());
			$server->receive($client->send());
		}
		self::assertSame(1, $server->getLocalSetting(TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL), 'In effect after the SETTINGS flush.');

		$server->close();
		$client->close();
	}

	public function testSubmitWindowUpdateDoesNotThrow()
	{
		$client = new TH2Session(false);
		$client->submitSettings([]);
		$client->submitWindowUpdate(0, 65535);          // connection-level
		self::assertNotSame('', $client->send(), 'A WINDOW_UPDATE is queued for the transport.');
		$client->close();
	}

	public function testConsumeAdvancesManualFlowControl()
	{
		$options = new \Prado\IO\Http2\TH2Options();
		$options->setNoAutoWindowUpdate(true);
		$server = new TH2Session(true, $options);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$consumedBytes = 0;
		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server) {
			$server->respond($stream, [':status' => '200']);
			$stream->markLocalClosed();
		});
		$server->attachEventHandler('onData', function ($s, $stream) use ($server, &$consumedBytes) {
			$bytes = strlen($stream->getContents());
			$server->consume($stream->getStreamId(), $bytes);   // manual flow-control accounting
			$consumedBytes += $bytes;
		});

		$request = $client->request([':method' => 'POST', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$request->write('payload-bytes');
		$request->markLocalClosed();
		for ($i = 0; $i < 8; $i++) {
			$server->receive($client->send());
			$client->receive($server->send());
		}

		self::assertSame(strlen('payload-bytes'), $consumedBytes, 'The server consumed the received DATA under manual flow control.');
		$server->close();
		$client->close();
	}

	public function testPingWithEmptyPayloadRoundTrips()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$serverSentPing = false;
		$server->attachEventHandler('onFrameSent', function ($s, $info) use (&$serverSentPing) {
			if ($info['type'] === TNgHttp2::FRAME_PING) {
				$serverSentPing = true;
			}
		});

		$client->ping();                                  // the zero-payload default branch
		$server->receive($client->send());
		$client->receive($server->send());
		self::assertTrue($serverSentPing, 'A zero-payload PING is answered with a PING ACK.');

		$server->close();
		$client->close();
	}

	public function testGoawaySenderCannotRequest()
	{
		$client = new TH2Session(false);
		$client->submitSettings([]);
		self::assertTrue($client->isRequestAllowed(), 'Requests allowed initially.');
		$client->goaway(TNgHttp2::NO_ERROR);
		self::assertFalse($client->isRequestAllowed(), 'The GOAWAY sender opens no new requests.');
		$client->close();
	}

	public function testSubmitSettingsRejectsInvalidValue()
	{
		$server = new TH2Session(true);
		// ENABLE_PUSH must be 0 or 1; nghttp2 rejects 2 with INVALID_ARGUMENT.
		$this->expectException(THttp2Exception::class);
		try {
			$server->submitSettings([TNgHttp2::SETTINGS_ENABLE_PUSH => 2]);
		} finally {
			$server->close();
		}
	}

	public function testSessionErrorRaisedOnConnectionSpecificHeader()
	{
		// RFC 9113 forbids connection-specific headers in HTTP/2; the server reports a session error.
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$errorMessage = null;
		$server->attachEventHandler('onSessionError', function ($s, $message) use (&$errorMessage) {
			$errorMessage = $message;
		});

		$client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/', 'connection' => 'keep-alive']);
		for ($i = 0; $i < 4; $i++) {
			$server->receive($client->send());
			$client->receive($server->send());
		}

		self::assertIsString($errorMessage, 'onSessionError delivered nghttp2 message string.');
		self::assertNotSame('', $errorMessage);
		$server->close();
		$client->close();
	}

	public function testFrameNotSentRaisedWhenRespondingAfterReset()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$serverStream = null;
		$server->attachEventHandler('onRequest', function ($s, $stream) use (&$serverStream) {
			$serverStream = $stream;
		});
		$notSent = null;
		$server->attachEventHandler('onFrameNotSent', function ($s, $info) use (&$notSent) {
			$notSent = $info;
		});

		$request = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$server->receive($client->send());        // server opens the stream
		$request->cancel();                          // client RST_STREAM
		$server->receive($client->send());        // server sees the reset

		$server->respond($serverStream, [':status' => '200']);   // queued for a now-closed stream
		for ($i = 0; $i < 4; $i++) {
			$client->receive($server->send());
			$server->receive($client->send());
		}

		self::assertIsArray($notSent, 'onFrameNotSent fired for the response on the reset stream.');
		self::assertArrayHasKey('error', $notSent, 'The payload carries the nghttp2 error code.');
		$server->close();
		$client->close();
	}

	public function testInvalidFrameEventDispatchesToHandler()
	{
		// onInvalidFrame fires from the on_invalid_frame_recv callback; assert its event plumbing
		// and payload shape directly (a natural protocol trigger is version-dependent).
		$session = new TH2Session(true);
		$received = null;
		$session->attachEventHandler('onInvalidFrame', function ($s, $info) use (&$received) {
			$received = $info;
		});
		$session->onInvalidFrame(['type' => TNgHttp2::FRAME_HEADERS, 'streamId' => 3, 'flags' => 0, 'error' => -531]);
		self::assertSame(['type' => TNgHttp2::FRAME_HEADERS, 'streamId' => 3, 'flags' => 0, 'error' => -531], $received);
		$session->close();
	}
}
