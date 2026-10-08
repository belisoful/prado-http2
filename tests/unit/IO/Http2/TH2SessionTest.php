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
		self::assertFalse($request->eof(), 'The response has not ended while the provider is deferred.');

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


	public function testClosedSessionRejectsNghttp2Calls()
	{
		$client = new TH2Session(false);
		$client->submitSettings([]);
		$stream = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$client->close();

		self::assertFalse($client->wantsIo(), 'A closed session wants no I/O.');
		$client->resumeStream($stream->getStreamId());   // a no-op on a closed session, not a use-after-free
		self::assertFalse($stream->isWritable(), 'Closing the session marks its streams closed.');
		self::assertTrue($stream->isLocalClosed());
		self::assertTrue($stream->eof());

		$calls = [
			'send' => fn () => $client->send(),
			'receive' => fn () => $client->receive('x'),
			'submitSettings' => fn () => $client->submitSettings([]),
			'request' => fn () => $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']),
			'respond' => fn () => $client->respond($stream, [':status' => '200']),
			'submitTrailers' => fn () => $client->submitTrailers($stream->getStreamId(), []),
			'resetStream' => fn () => $client->resetStream($stream->getStreamId()),
			'goaway' => fn () => $client->goaway(),
			'ping' => fn () => $client->ping(),
			'isRequestAllowed' => fn () => $client->isRequestAllowed(),
			'submitWindowUpdate' => fn () => $client->submitWindowUpdate(0, 1),
			'consume' => fn () => $client->consume($stream->getStreamId(), 1),
			'getRemoteSetting' => fn () => $client->getRemoteSetting(TNgHttp2::SETTINGS_MAX_CONCURRENT_STREAMS),
			'getLocalSetting' => fn () => $client->getLocalSetting(TNgHttp2::SETTINGS_MAX_CONCURRENT_STREAMS),
		];
		foreach ($calls as $name => $call) {
			try {
				$call();
				self::fail("$name() on a closed session did not throw.");
			} catch (THttp2Exception $e) {
				self::assertStringContainsString('closed', $e->getMessage(), "$name() reports the closed session.");
			}
		}
	}

	public function testSessionIsCollectedOnceUnreferenced()
	{
		$client = new TH2Session(false);
		$client->submitSettings([]);
		$client->send();
		$weak = \WeakReference::create($client);
		unset($client);
		gc_collect_cycles();
		self::assertNull($weak->get(), 'The registry holds sessions weakly, so an unreferenced session is collected and its nghttp2 session freed.');
	}

	public function testClientSendsTrailersServerReceivesThem()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$requests = 0;
		$body = '';
		$trailer = null;
		$eofOnTrailers = null;
		$server->attachEventHandler('onRequest', function () use (&$requests) {
			$requests++;
		});
		$server->attachEventHandler('onData', function ($s, $stream) use (&$body) {
			$body .= $stream->getContents();
		});
		$server->attachEventHandler('onTrailers', function ($s, $stream) use (&$trailer, &$eofOnTrailers) {
			$trailer = $stream->getHeader('x-checksum');
			$eofOnTrailers = $stream->eof();
		});

		$request = $client->request([':method' => 'POST', ':scheme' => 'http', ':authority' => 'h', ':path' => '/upload']);
		$request->write('payload');
		$request->sendTrailers(['x-checksum' => 'abc123']);
		for ($i = 0; $i < 8; $i++) {
			$server->receive($client->send());
			$client->receive($server->send());
		}

		self::assertSame(1, $requests, 'The trailing HEADERS did not raise a second onRequest.');
		self::assertSame('payload', $body, 'The body arrived before the trailers.');
		self::assertSame('abc123', $trailer, 'The server merged the request trailers into the stream headers.');
		self::assertTrue($eofOnTrailers, 'The trailers carried END_STREAM.');
		$server->close();
		$client->close();
	}

	public function testRepeatedRequestHeadersAreJoined()
	{
		// Only a raw nghttp2 client can send a repeated field (TH2Session headers are a name => value
		// map); the TH2Session server keeps every value.
		$server = new TH2Session(true);
		$server->submitSettings([]);
		$headers = null;
		$server->attachEventHandler('onRequest', function ($s, $stream) use (&$headers) {
			$headers = $stream->getHeaders();
		});

		$ffi = TNgHttp2::ffi();
		$cbs = $ffi->new('nghttp2_session_callbacks*');
		$ffi->nghttp2_session_callbacks_new(\FFI::addr($cbs));
		$client = $ffi->new('nghttp2_session*');
		$ffi->nghttp2_session_client_new(\FFI::addr($client), $cbs, null);
		$ffi->nghttp2_submit_settings($client, 0, null, 0);

		$pairs = [
			[':method', 'GET'], [':scheme', 'http'], [':authority', 'h'], [':path', '/'],
			['cookie', 'a=1'], ['cookie', 'b=2'], ['x-tag', 'one'], ['x-tag', 'two'],
		];
		$nva = $ffi->new('nghttp2_nv[' . count($pairs) . ']');
		$keep = [];
		foreach ($pairs as $i => [$name, $value]) {
			$nameBuf = $ffi->new('uint8_t[' . (strlen($name) + 1) . ']');
			$valueBuf = $ffi->new('uint8_t[' . (strlen($value) + 1) . ']');
			\FFI::memcpy($nameBuf, $name, strlen($name));
			\FFI::memcpy($valueBuf, $value, strlen($value));
			$nva[$i]->name = $ffi->cast('uint8_t*', $nameBuf);
			$nva[$i]->value = $ffi->cast('uint8_t*', $valueBuf);
			$nva[$i]->namelen = strlen($name);
			$nva[$i]->valuelen = strlen($value);
			$nva[$i]->flags = TNgHttp2::NV_FLAG_NONE;
			$keep[] = $nameBuf;
			$keep[] = $valueBuf;
		}
		self::assertGreaterThan(0, $ffi->nghttp2_submit_request2($client, null, $nva, count($pairs), null, null));

		while (true) {
			$dataPtr = $ffi->new('uint8_t*');
			$n = $ffi->nghttp2_session_mem_send2($client, \FFI::addr($dataPtr));
			if ($n <= 0) {
				break;
			}
			$server->receive(\FFI::string($dataPtr, $n));
		}
		$ffi->nghttp2_session_del($client);
		$ffi->nghttp2_session_callbacks_del($cbs);

		self::assertNotNull($headers, 'The server saw the request.');
		self::assertSame('a=1; b=2', $headers['cookie'], 'cookie crumbs rejoin with "; ".');
		self::assertSame('one, two', $headers['x-tag'], 'Other repeated fields combine with ", ".');
		$server->close();
	}

	public function testHeaderNamesAreLowercasedOnSubmit()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$seen = null;
		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server, &$seen) {
			$seen = $stream->getHeaders();
			$server->respond($stream, [':status' => '200', 'Content-Type' => 'text/plain']);
			$stream->markLocalClosed();
		});
		$response = null;
		$client->attachEventHandler('onResponse', function ($s, $stream) use (&$response) {
			$response = $stream->getHeaders();
		});

		$request = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/', 'X-Custom' => 'v']);
		$request->markLocalClosed();
		self::assertSame('v', $request->getHeader('x-custom'), 'The client stream carries the lowercased name.');
		self::assertNull($request->getHeader('X-Custom'));
		for ($i = 0; $i < 4; $i++) {
			$server->receive($client->send());
			$client->receive($server->send());
		}

		self::assertSame('v', $seen['x-custom'] ?? null, 'The server received the lowercased request header.');
		self::assertSame('text/plain', $response['content-type'] ?? null, 'The client received the lowercased response header.');
		$server->close();
		$client->close();
	}

	public function testPeerResetMakesStreamNonWritable()
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);

		$serverStream = null;
		$server->attachEventHandler('onRequest', function ($s, $stream) use (&$serverStream) {
			$serverStream = $stream;
		});
		$writableInOnClose = null;
		$server->attachEventHandler('onClose', function ($s, $stream) use (&$writableInOnClose) {
			$writableInOnClose = $stream->isWritable();
		});

		$stream = $client->request([':method' => 'POST', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$server->receive($client->send());
		self::assertTrue($serverStream->isWritable(), 'Open before the reset.');
		$stream->cancel();
		$server->receive($client->send());

		self::assertFalse($writableInOnClose, 'onClose sees the stream closed in both directions.');
		self::assertFalse($serverStream->isWritable());
		try {
			$serverStream->write('too late');
			self::fail('A write after the peer reset did not throw.');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('closed', $e->getMessage());
		}
		$server->close();
		$client->close();
	}

	public function testHandlerExceptionIsRethrownAfterReceiveAndTheSessionStaysUsable()
	{
		// PHP cannot throw out of an FFI callback (a fatal error). A handler's Throwable is held until
		// nghttp2 returns, every frame in the input is still processed, and receive() rethrows it.
		[$server, $client] = $this->newPair();
		$serverStream = null;
		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server, &$serverStream) {
			$server->respond($stream, [':status' => '200']);
			$serverStream = $stream;
		});
		$chunks = [];
		$server->attachEventHandler('onData', function ($s, $stream) use (&$chunks) {
			$chunks[] = $stream->getContents();
			if (count($chunks) === 1) {
				throw new \LogicException('handler boom');
			}
		});
		$request = $client->request([':method' => 'POST', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$server->receive($client->send());
		$client->receive($server->send());
		$request->write('A');
		$bytes = $client->send();
		$request->write('B');
		$bytes .= $client->send();
		$request->markLocalClosed();
		$bytes .= $client->send();

		try {
			$server->receive($bytes);
			self::fail('receive() rethrows the handler exception.');
		} catch (\LogicException $e) {
			self::assertSame('handler boom', $e->getMessage());
		}
		self::assertSame(['A', 'B'], $chunks, 'The frames after the throwing handler were still delivered.');
		self::assertTrue($server->getStream(1)->eof(), 'END_STREAM after the throwing handler was processed.');

		$closed = false;
		$client->attachEventHandler('onClose', function () use (&$closed) {
			$closed = true;
		});
		$serverStream->markLocalClosed();
		$this->pump($server, $client);
		self::assertTrue($closed, 'The session stays usable: the response completed.');
		$server->close();
		$client->close();
	}

	public function testDataProviderFailureResetsOnlyItsStream()
	{
		// A Throwable inside the data provider returns NGHTTP2_ERR_TEMPORAL_CALLBACK_FAILURE: nghttp2
		// resets that stream, send() rethrows, and the session serves the next request.
		[$server, $client] = $this->newPair();
		$requests = 0;
		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server, &$requests) {
			$requests++;
			$server->respond($stream, [':status' => '200']);
			if ($requests === 1) {
				$stream->write('body');
				$stream->sendTrailers(['X-Dup' => '1', 'x-dup' => '2']);   // normalizeHeaders() throws inside the provider
			} else {
				$stream->markLocalClosed();
			}
		});
		$closedStreams = [];
		$client->attachEventHandler('onClose', function ($s, $stream) use (&$closedStreams) {
			$closedStreams[] = $stream->getStreamId();
		});
		$first = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$first->markLocalClosed();

		$caught = null;
		try {
			$this->pump($server, $client);
		} catch (THttp2Exception $e) {
			$caught = $e;
		}
		self::assertNotNull($caught, 'send() rethrows the Throwable raised inside the data provider.');
		self::assertStringContainsString('x-dup', $caught->getMessage());
		$this->pump($server, $client);
		self::assertSame([1], $closedStreams, 'nghttp2 reset the failing stream.');

		$second = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/2']);
		$second->markLocalClosed();
		$this->pump($server, $client);
		self::assertSame(2, $requests, 'The session served the next request.');
		self::assertSame([1, 3], $closedStreams);
		$server->close();
		$client->close();
	}

	public function testCloseInsideAHandlerIsDeferredUntilNghttp2Returns()
	{
		// Freeing the nghttp2 session from inside one of its callbacks is a use-after-free (it
		// crashed the process). close() now takes effect for PHP at once and frees nghttp2 after.
		[$server, $client] = $this->newPair();
		$requests = 0;
		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server, &$requests) {
			$requests++;
			$server->close();
			$server->close();   // idempotent inside the callback too
		});
		$client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/2']);

		$server->receive($client->send());
		self::assertSame(1, $requests, 'The second request reached a closed session and was dropped.');
		self::assertFalse($server->wantsIo(), 'The session is closed once receive() returns.');
		$this->expectException(THttp2Exception::class);
		$server->send();
	}

	public function testClientDeclinesServerPushByDefault()
	{
		$client = new TH2Session(false);
		$client->submitSettings([]);
		$client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);

		$ffi = TNgHttp2::ffi();
		$cbs = $ffi->new('nghttp2_session_callbacks*');
		$ffi->nghttp2_session_callbacks_new(\FFI::addr($cbs));
		$raw = $ffi->new('nghttp2_session*');
		$ffi->nghttp2_session_server_new(\FFI::addr($raw), $cbs, null);
		$ffi->nghttp2_submit_settings($raw, 0, null, 0);   // the server preface; the client applies its settings after it
		$this->rawFeed($ffi, $raw, $client->send());
		$client->receive($this->rawDrain($ffi, $raw));   // the peer's SETTINGS ACK puts the local settings in effect
		self::assertSame(0, $client->getLocalSetting(TNgHttp2::SETTINGS_ENABLE_PUSH), 'A client advertises SETTINGS_ENABLE_PUSH 0 unless told otherwise.');
		$keep = [];
		$nva = $this->rawNv($ffi, [[':method', 'GET'], [':scheme', 'http'], [':authority', 'h'], [':path', '/pushed.css']], $keep);
		self::assertGreaterThan(0, $ffi->nghttp2_submit_push_promise($raw, 0, 1, $nva, 4, null), 'nghttp2 queues the push and refuses it at send time.');
		self::assertNotContains(TNgHttp2::FRAME_PUSH_PROMISE, $this->frameTypes($this->rawDrain($ffi, $raw)), 'No PUSH_PROMISE reaches a client that declined push.');
		$ffi->nghttp2_session_del($raw);
		$ffi->nghttp2_session_callbacks_del($cbs);

		[$server, $opted] = $this->newPair();
		$opted->submitSettings([TNgHttp2::SETTINGS_ENABLE_PUSH => 1]);
		$this->pump($server, $opted, 2);
		self::assertSame(1, $opted->getLocalSetting(TNgHttp2::SETTINGS_ENABLE_PUSH), 'An explicit value is kept.');
		$opted->close();
		$server->close();
		$client->close();
	}

	public function testPushedStreamHeadersDoNotPolluteTheRequestStream()
	{
		// A PUSH_PROMISE header block arrives under the associated request stream's id. It used to be
		// kept as pending headers and merged into the real response (the request's :path became the
		// pushed path). Pushed streams have no TH2Stream; their headers are ignored.
		$client = new TH2Session(false);
		$client->submitSettings([TNgHttp2::SETTINGS_ENABLE_PUSH => 1]);
		$events = [];
		foreach (['onResponse', 'onTrailers', 'onInformationalResponse', 'onData', 'onClose'] as $event) {
			$client->attachEventHandler($event, function ($s, $stream) use (&$events, $event) {
				$events[] = $event . ':' . $stream->getStreamId();
			});
		}
		$request = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$request->markLocalClosed();

		$ffi = TNgHttp2::ffi();
		$cbs = $ffi->new('nghttp2_session_callbacks*');
		$ffi->nghttp2_session_callbacks_new(\FFI::addr($cbs));
		$raw = $ffi->new('nghttp2_session*');
		$ffi->nghttp2_session_server_new(\FFI::addr($raw), $cbs, null);
		$ffi->nghttp2_submit_settings($raw, 0, null, 0);
		$this->rawFeed($ffi, $raw, $client->send());
		$client->receive($this->rawDrain($ffi, $raw));
		$this->rawFeed($ffi, $raw, $client->send());

		$keep = [];
		$promised = $ffi->nghttp2_submit_push_promise($raw, 0, 1, $this->rawNv($ffi, [[':method', 'GET'], [':scheme', 'http'], [':authority', 'h'], [':path', '/pushed.css']], $keep), 4, null);
		self::assertSame(2, $promised, 'The server pushed stream 2.');
		self::assertSame(0, $ffi->nghttp2_submit_response2($raw, 1, $this->rawNv($ffi, [[':status', '200'], ['x-main', 'yes']], $keep), 2, null));
		self::assertSame(0, $ffi->nghttp2_submit_response2($raw, $promised, $this->rawNv($ffi, [[':status', '200'], ['x-pushed', 'yes']], $keep), 2, null));
		for ($i = 0; $i < 4; $i++) {
			$client->receive($this->rawDrain($ffi, $raw));
			$this->rawFeed($ffi, $raw, $client->send());
		}
		$ffi->nghttp2_session_del($raw);
		$ffi->nghttp2_session_callbacks_del($cbs);

		self::assertSame('/', $request->getHeader(':path'), 'The request stream keeps its own :path.');
		self::assertSame('200', $request->getHeader(':status'));
		self::assertSame('yes', $request->getHeader('x-main'));
		self::assertNull($request->getHeader('x-pushed'), 'The pushed response went to no TH2Stream.');
		self::assertSame(['onResponse:1', 'onClose:1'], $events, 'Only the request stream raised events.');
		$client->close();
	}

	public function testStreamCloseEndsTheStreamWhenThePeerHasFinished()
	{
		// close() on a headers-only response used to leave the deferred provider parked forever: the
		// peer never got END_STREAM. It now resumes the stream so END_STREAM goes out.
		[$server, $client] = $this->newPair();
		$serverStream = null;
		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server, &$serverStream) {
			$server->respond($stream, [':status' => '204']);
			$serverStream = $stream;
		});
		$closed = false;
		$client->attachEventHandler('onClose', function () use (&$closed) {
			$closed = true;
		});
		$request = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/']);
		$request->markLocalClosed();
		$this->pump($server, $client, 2);
		self::assertFalse($request->eof(), 'The response has not ended before close().');

		$serverStream->close();
		$this->pump($server, $client);
		self::assertTrue($closed, 'The peer saw END_STREAM.');
		self::assertTrue($request->eof());
		self::assertSame('204', $request->getHeader(':status'));
		$server->close();
		$client->close();
	}

	public function testStreamCloseCancelsAPeerThatIsStillSending()
	{
		[$server, $client] = $this->newPair();
		$serverStream = null;
		$server->attachEventHandler('onRequest', function ($s, $stream) use ($server, &$serverStream) {
			$server->respond($stream, [':status' => '200']);
			$serverStream = $stream;
		});
		$closed = false;
		$client->attachEventHandler('onClose', function () use (&$closed) {
			$closed = true;
		});
		$request = $client->request([':method' => 'POST', ':scheme' => 'http', ':authority' => 'h', ':path' => '/upload']);
		$request->write('part one');
		$this->pump($server, $client, 2);
		self::assertTrue($request->isWritable(), 'The client is still uploading.');

		$serverStream->close();
		$this->pump($server, $client);
		self::assertTrue($closed, 'The server cancelled the stream (RST_STREAM) rather than sending a truncated response.');
		self::assertFalse($request->isWritable());
		$serverStream->close();   // idempotent: nothing more is submitted
		$server->close();
		$client->close();
	}

	public function testPseudoHeadersAreSentFirstWhateverTheGivenOrder()
	{
		// A pseudo-header after a regular field is malformed (RFC 9113 §8.3): nghttp2 submitted it as
		// given and the peer reset the stream with no onRequest. The headers are reordered on submit.
		[$server, $client] = $this->newPair();
		$headers = null;
		$server->attachEventHandler('onRequest', function ($s, $stream) use (&$headers) {
			$headers = $stream->getHeaders();
		});
		$request = $client->request(['x-first' => '1', ':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', 'X-Second' => '2', ':path' => '/']);
		self::assertSame([':method', ':scheme', ':authority', ':path', 'x-first', 'x-second'], array_keys($request->getHeaders()), 'The stream keeps the normalized order.');
		$request->markLocalClosed();
		$this->pump($server, $client, 2);
		self::assertNotNull($headers, 'The server accepted the request.');
		self::assertSame('1', $headers['x-first']);
		self::assertSame('2', $headers['x-second']);
		$server->close();
		$client->close();
	}

	public function testHeaderNamesDifferingOnlyByCaseAreRejected()
	{
		$client = new TH2Session(false);
		$client->submitSettings([]);
		try {
			$client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/', 'X-Tag' => 'a', 'x-tag' => 'b']);
			self::fail('A repeated name throws.');
		} catch (THttp2Exception $e) {
			self::assertStringContainsString('x-tag', $e->getMessage());
		}
		self::assertNull($client->getStream(1), 'Nothing was submitted.');
		$client->close();
	}

	public function testPendingHeadersAreDroppedWhenAStreamIsResetMidBlock()
	{
		// nghttp2 resets a request with a connection-specific header while its block is still being
		// delivered: on_frame_recv never runs for it, so the pending entry has to go with the stream.
		$server = new class (true) extends TH2Session {
			public function pendingHeaderBlocks(): array
			{
				return $this->getPendingHeadersDirect();
			}
		};
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);
		$client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'h', ':path' => '/', 'connection' => 'keep-alive']);
		$this->pump($server, $client, 2);
		if (!$server->wantsIo()) {
			// nghttp2 before 1.70 answers the malformed block with GOAWAY (a connection error) instead of
			// RST_STREAM, so no stream-level callback runs; the block goes with the session.
			$server->close();
		}
		self::assertSame([], $server->pendingHeaderBlocks(), 'No header block lingers for the reset stream.');
		$server->close();
		$client->close();
	}

	/**
	 * @return array{TH2Session, TH2Session} A server and a client session with settings submitted.
	 */
	private function newPair(): array
	{
		$server = new TH2Session(true);
		$client = new TH2Session(false);
		$server->submitSettings([]);
		$client->submitSettings([]);
		return [$server, $client];
	}

	private function pump(TH2Session $server, TH2Session $client, int $rounds = 8): void
	{
		for ($i = 0; $i < $rounds; $i++) {
			$server->receive($client->send());
			$client->receive($server->send());
		}
	}

	/**
	 * Builds an nghttp2_nv[] for a raw nghttp2 session; $keep holds the byte buffers alive.
	 * @param \FFI $ffi The bound nghttp2 FFI instance.
	 * @param array<int, array{string, string}> $pairs The name/value pairs.
	 * @param array<int, mixed> &$keep Receives the name/value buffers.
	 */
	private function rawNv(\FFI $ffi, array $pairs, array &$keep): \FFI\CData
	{
		$nva = $ffi->new('nghttp2_nv[' . count($pairs) . ']');
		foreach ($pairs as $i => [$name, $value]) {
			$nameBuf = $ffi->new('uint8_t[' . (strlen($name) + 1) . ']');
			$valueBuf = $ffi->new('uint8_t[' . (strlen($value) + 1) . ']');
			\FFI::memcpy($nameBuf, $name, strlen($name));
			\FFI::memcpy($valueBuf, $value, strlen($value));
			$nva[$i]->name = $ffi->cast('uint8_t*', $nameBuf);
			$nva[$i]->value = $ffi->cast('uint8_t*', $valueBuf);
			$nva[$i]->namelen = strlen($name);
			$nva[$i]->valuelen = strlen($value);
			$nva[$i]->flags = TNgHttp2::NV_FLAG_NONE;
			$keep[] = $nameBuf;
			$keep[] = $valueBuf;
		}
		return $nva;
	}

	private function rawFeed(\FFI $ffi, \FFI\CData $raw, string $bytes): void
	{
		if ($bytes === '') {
			return;
		}
		$buffer = $ffi->new('uint8_t[' . strlen($bytes) . ']');
		\FFI::memcpy($buffer, $bytes, strlen($bytes));
		self::assertGreaterThanOrEqual(0, $ffi->nghttp2_session_mem_recv2($raw, $ffi->cast('uint8_t*', $buffer), strlen($bytes)), 'The raw peer accepted the bytes.');
	}

	/**
	 * @param string $bytes
	 * @return int[] The frame types in a byte sequence of whole HTTP/2 frames.
	 */
	private function frameTypes(string $bytes): array
	{
		$types = [];
		for ($offset = 0; $offset + 9 <= strlen($bytes);) {
			$length = unpack('N', "\0" . substr($bytes, $offset, 3))[1];
			$types[] = ord($bytes[$offset + 3]);
			$offset += 9 + $length;
		}
		return $types;
	}

	private function rawDrain(\FFI $ffi, \FFI\CData $raw): string
	{
		$out = '';
		while (true) {
			$pointer = $ffi->new('uint8_t*');
			$n = $ffi->nghttp2_session_mem_send2($raw, \FFI::addr($pointer));
			if ($n <= 0) {
				break;
			}
			$out .= \FFI::string($pointer, $n);
		}
		return $out;
	}
}
