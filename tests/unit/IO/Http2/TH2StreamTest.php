<?php

use Prado\Exceptions\TCancelledException;
use Prado\IO\Async\TAsync;
use Prado\IO\Async\TCancellationToken;
use Prado\IO\Http2\TH2Session;
use Prado\IO\Http2\TH2Stream;
use Prado\IO\Http2\THttp2Exception;

/**
 * Unit-tests {@see TH2Stream} in isolation with a mocked session, so the duplex buffer and
 * StreamInterface behavior is covered without libnghttp2.
 */
class TH2StreamTest extends PHPUnit\Framework\TestCase
{
	private function stream(array $headers = []): TH2Stream
	{
		$session = $this->createMock(TH2Session::class);   // write() calls resumeStream() — a no-op here
		return new TH2Stream($session, 1, $headers);
	}

	public function testHeaders()
	{
		$stream = $this->stream([':method' => 'GET', ':path' => '/chat']);
		self::assertSame(1, $stream->getStreamId());
		self::assertSame([':method' => 'GET', ':path' => '/chat'], $stream->getHeaders());
		self::assertSame('GET', $stream->getHeader(':method'));
		self::assertNull($stream->getHeader(':authority'));
	}

	public function testMergeHeaders()
	{
		$stream = $this->stream([':method' => 'GET']);
		$stream->mergeHeaders([':status' => '200', ':method' => 'POST']);
		self::assertSame('200', $stream->getHeader(':status'));
		self::assertSame('POST', $stream->getHeader(':method'), 'Merge overwrites.');
	}

	public function testIncomingReadCapAndTell()
	{
		$stream = $this->stream();
		$stream->pushIncoming('abcdef');
		self::assertSame(0, $stream->tell());
		self::assertSame('abc', $stream->read(3));         // capped to length
		self::assertSame(3, $stream->tell());
		self::assertSame('', $stream->read(0), 'A zero length reads nothing.');
		self::assertSame('def', $stream->read(100), 'A length over the buffer returns what is there.');
		self::assertSame('', $stream->read(10), 'An empty buffer reads nothing.');
		self::assertSame(6, $stream->tell());
	}

	public function testGetContentsDrains()
	{
		$stream = $this->stream();
		$stream->pushIncoming('hello');
		self::assertSame('hello', $stream->getContents());
		self::assertSame('', $stream->getContents(), 'getContents() drains the buffer.');
		self::assertSame(5, $stream->tell());
	}

	public function testOutgoingWriteDrain()
	{
		$stream = $this->stream();
		self::assertFalse($stream->hasOutgoing());
		self::assertSame(5, $stream->write('hello'));
		self::assertSame(3, $stream->write('!!!'));
		self::assertTrue($stream->hasOutgoing());
		self::assertSame('hel', $stream->drainOutgoing(3));     // capped
		self::assertSame('lo!!!', $stream->drainOutgoing(100)); // remainder
		self::assertSame('', $stream->drainOutgoing(10));
		self::assertFalse($stream->hasOutgoing());
	}

	public function testReadableWritableTransitions()
	{
		$stream = $this->stream();
		self::assertTrue($stream->isReadable());
		self::assertTrue($stream->isWritable());

		$stream->markLocalClosed();
		self::assertTrue($stream->isLocalClosed());
		self::assertFalse($stream->isWritable());

		$stream->pushIncoming('x');
		$stream->markRemoteClosed();
		self::assertTrue($stream->isReadable(), 'Readable while buffered, even after remote close.');
		self::assertFalse($stream->eof());
		self::assertSame('x', $stream->read(10));
		self::assertTrue($stream->eof(), 'EOF once the peer closed and the buffer drained.');
		self::assertFalse($stream->isReadable());
	}

	public function testCloseClearsBuffersAndFlags()
	{
		$stream = $this->stream();
		$stream->pushIncoming('in');
		$stream->write('out');
		$stream->close();
		self::assertFalse($stream->hasOutgoing());
		self::assertTrue($stream->eof());
		self::assertFalse($stream->isWritable(), 'A closed stream is not writable.');
		self::assertFalse($stream->isReadable(), 'A closed stream is not readable.');
	}

	public function testReadAfterCloseThrows()
	{
		$stream = $this->stream();
		$stream->pushIncoming('in');
		$stream->close();
		$this->expectException(\RuntimeException::class);
		$stream->read(10);
	}

	public function testGetContentsAfterCloseThrows()
	{
		$stream = $this->stream();
		$stream->pushIncoming('in');
		$stream->close();
		$this->expectException(\RuntimeException::class);
		$stream->getContents();
	}

	public function testWriteAfterCloseThrows()
	{
		$stream = $this->stream();
		$stream->close();
		$this->expectException(\RuntimeException::class);
		$stream->write('x');
	}

	public function testWriteAfterMarkLocalClosedThrows()
	{
		$stream = $this->stream();
		$stream->markLocalClosed();
		self::assertFalse($stream->isWritable(), 'A local-closed stream is not writable.');
		$this->expectException(\RuntimeException::class);
		$stream->write('late');
	}

	public function testReadNegativeLengthThrows()
	{
		$stream = $this->stream();
		$stream->pushIncoming('data');
		$this->expectException(\RuntimeException::class);
		$stream->read(-1);
	}

	public function testDetachClosesAndReturnsNull()
	{
		$stream = $this->stream();
		$stream->pushIncoming('in');
		self::assertNull($stream->detach());
		self::assertTrue($stream->eof());
		self::assertFalse($stream->isReadable(), 'A detached stream is not readable.');
		self::assertFalse($stream->isWritable(), 'A detached stream is not writable.');
	}

	public function testToStringOnDetachedStreamReturnsEmpty()
	{
		$stream = $this->stream();
		$stream->pushIncoming('body');
		$stream->close();
		self::assertSame('', (string) $stream, 'Casting a closed stream never throws; it yields an empty string.');
	}

	public function testMarkLocalClosedPreservesQueuedBytesUnlikeClose()
	{
		$stream = $this->stream();
		$stream->write('body');
		$stream->markLocalClosed();
		self::assertTrue($stream->hasOutgoing(), 'markLocalClosed() keeps the queued body to flush.');
		self::assertSame('body', $stream->drainOutgoing(100));

		$other = $this->stream();
		$other->write('body');
		$other->close();
		self::assertFalse($other->hasOutgoing(), 'close() discards the queued body.');
	}

	public function testToStringDrainsIncoming()
	{
		$stream = $this->stream();
		$stream->pushIncoming('body');
		self::assertSame('body', (string) $stream);
		self::assertSame('', (string) $stream);
	}

	public function testNotSeekable()
	{
		$stream = $this->stream();
		self::assertFalse($stream->isSeekable());
		self::assertNull($stream->getSize());
		self::assertSame([], $stream->getMetadata());
		self::assertNull($stream->getMetadata('timed_out'));
	}

	public function testSeekThrows()
	{
		$this->expectException(\RuntimeException::class);
		$this->stream()->seek(0);
	}

	public function testRewindThrows()
	{
		$this->expectException(\RuntimeException::class);
		$this->stream()->rewind();
	}

	public function testCancelDelegatesToSessionReset()
	{
		$session = $this->createMock(TH2Session::class);
		$session->expects(self::once())->method('resetStream')->with(7, \Prado\IO\Http2\TNgHttp2::CANCEL);
		$stream = new TH2Stream($session, 7, []);
		$stream->cancel();
	}


	public function testMarkClosedClosesBothDirectionsAndKeepsBuffers()
	{
		$stream = $this->stream();
		$stream->pushIncoming('tail');
		$stream->markClosed();
		self::assertFalse($stream->isWritable());
		self::assertTrue($stream->isLocalClosed());
		self::assertTrue($stream->isReadable(), 'Buffered bytes stay readable.');
		self::assertFalse($stream->eof(), 'Not at eof until the buffer drains.');
		self::assertSame('tail', $stream->getContents());
		self::assertTrue($stream->eof());
		self::assertFalse($stream->isReadable());
		$this->expectException(\RuntimeException::class);
		$stream->write('late');
	}

	public function testPushIncomingAfterCloseIsDropped()
	{
		$stream = $this->spyStream();
		$stream->close();
		$stream->pushIncoming(str_repeat('z', 1000));
		self::assertSame('', $stream->rawIncoming(), 'A detached stream buffers nothing: no reader can drain it.');
	}

	public function testDrainOutgoingInChunksKeepsOrderAndCompacts()
	{
		// Draining used to rebuild the remaining buffer per 16 KiB frame (quadratic in the body size).
		$stream = $this->spyStream();
		$body = $this->pattern(3 << 20);
		$stream->write($body);
		$drained = '';
		$shrank = false;
		while ($stream->hasOutgoing()) {
			$drained .= $stream->drainOutgoing(16384);
			$shrank = $shrank || strlen($stream->rawOutgoing()) < strlen($body);
		}
		self::assertTrue($drained === $body, 'Every byte came out once, in order.');
		self::assertTrue($shrank, 'The buffer was compacted while partly consumed.');
		self::assertSame('', $stream->rawOutgoing(), 'A fully drained buffer is released.');
		self::assertSame('', $stream->drainOutgoing(16384));
		$stream->write('more');
		self::assertSame('more', $stream->drainOutgoing(100), 'Writes after a drain start from a clean buffer.');
	}

	public function testReadInChunksKeepsOrderAndTell()
	{
		$stream = $this->spyStream();
		$body = $this->pattern(3 << 20);
		$stream->pushIncoming($body);
		$read = '';
		while (!$stream->eof() && ($chunk = $stream->read(4096)) !== '') {
			$read .= $chunk;
		}
		self::assertTrue($read === $body, 'Every byte was read once, in order.');
		self::assertSame(strlen($body), $stream->tell());
		self::assertSame('', $stream->rawIncoming());
		$stream->pushIncoming('tail');
		self::assertSame('ta', $stream->read(2));
		self::assertSame('il', $stream->getContents(), 'getContents() returns only the unread remainder.');
		self::assertSame(strlen($body) + 4, $stream->tell());
	}

	public function testSendTrailersAfterTheBodyEndedThrows()
	{
		$stream = $this->stream();
		$stream->markLocalClosed();
		$this->expectException(\RuntimeException::class);
		$stream->sendTrailers(['x-checksum' => 'abc']);
	}

	public function testSendTrailersAfterCloseThrows()
	{
		$stream = $this->stream();
		$stream->close();
		$this->expectException(\RuntimeException::class);
		$stream->sendTrailers(['x-checksum' => 'abc']);
	}

	public function testMergeHeadersKeepsDigitOnlyNames()
	{
		$stream = $this->stream(['123' => 'a', 'x' => 'y']);
		$stream->mergeHeaders(['z' => '1', 'x' => 'replaced']);
		self::assertSame('a', $stream->getHeader('123'), 'array_merge renumbered the key to 0; array_replace keeps it.');
		self::assertSame('replaced', $stream->getHeader('x'));
		self::assertSame('1', $stream->getHeader('z'));
	}

	public function testCloseCancelsWhenThePeerIsStillSending()
	{
		$session = $this->createMock(TH2Session::class);
		$session->expects(self::once())->method('resetStream')->with(5, \Prado\IO\Http2\TNgHttp2::CANCEL);
		$session->expects(self::never())->method('resumeStream');
		$stream = new TH2Stream($session, 5, []);
		$stream->pushIncoming('partial');
		$stream->close();
		$stream->close();   // idempotent: no second reset
		self::assertFalse($stream->isReadable());
	}

	public function testCloseEndsTheLocalSideWhenThePeerHasFinished()
	{
		$session = $this->createMock(TH2Session::class);
		$session->expects(self::never())->method('resetStream');
		$session->expects(self::once())->method('resumeStream')->with(5);
		$stream = new TH2Stream($session, 5, []);
		$stream->markRemoteClosed();
		$stream->close();
		self::assertFalse($stream->isWritable());
	}

	public function testCloseAfterBothSidesEndedTouchesNothing()
	{
		$session = $this->createMock(TH2Session::class);
		$session->expects(self::never())->method('resetStream');
		$session->expects(self::never())->method('resumeStream');
		$stream = new TH2Stream($session, 5, []);
		$stream->markClosed();   // nghttp2 already closed it
		$stream->close();
		self::assertTrue($stream->eof());
	}

	public function testCloseSurvivesAClosedSession()
	{
		$session = $this->createMock(TH2Session::class);
		$session->method('resetStream')->willThrowException(new \Prado\IO\Http2\THttp2Exception('http2_session_closed'));
		$stream = new TH2Stream($session, 5, []);
		$stream->close();
		self::assertFalse($stream->isReadable());
	}

	// =========================================================================
	// readAsync() / waitReadable(): the fiber-aware read on the PRADO 4.4 event loop
	// =========================================================================

	public function testReadAsyncReturnsBufferedBytesAtOnce()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->pushIncoming('abcdef');
		self::assertSame('abc', $stream->readAsync(3), 'Buffered bytes return without a fiber or a loop.');
		self::assertSame('def', $stream->readAsync(100));
		self::assertSame(6, $stream->tell());
		self::assertSame('', $stream->readAsync(0), 'A zero length reads nothing and never waits.');
	}

	public function testReadAsyncReturnsEmptyOnceTheRemoteSideClosedAndTheBufferDrained()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->pushIncoming('tail');
		$stream->markRemoteClosed();
		self::assertSame('tail', $stream->readAsync(10), 'The buffered bytes come first.');
		self::assertSame('', $stream->readAsync(10), 'End of stream returns \'\' without waiting.');
		self::assertTrue($stream->eof());
	}

	public function testReadAsyncWaitsUntilPushIncomingDeliversBytes()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$future = TAsync::run(fn () => $stream->readAsync(10));
		self::assertFalse($future->isComplete(), 'The fiber waits while nothing is buffered.');
		$stream->pushIncoming('late');
		self::assertFalse($future->isComplete(), 'The fiber resumes from the loop, not inside pushIncoming().');
		self::assertSame('late', $future->await());
		self::assertSame(4, $stream->tell());
	}

	public function testReadAsyncReadsABodyInPiecesUntilEndOfStream()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$seen = [];
		$future = TAsync::run(function () use ($stream, &$seen) {
			while (($bytes = $stream->readAsync(1024)) !== '') {
				$seen[] = $bytes;
			}
			return implode('', $seen);
		});
		$stream->pushIncoming('one ');
		TAsync::sleep(0.001);                                  // the main fiber runs the loop: the worker takes its step
		self::assertSame(['one '], $seen, 'The first piece is consumed before the second arrives.');
		self::assertFalse($future->isComplete());
		$stream->pushIncoming('two');
		$stream->markRemoteClosed();
		self::assertSame('one two', $future->await());
		self::assertSame(['one ', 'two'], $seen);
	}

	public function testReadAsyncReturnsEmptyWhenTheRemoteSideClosesWhileWaiting()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$future = TAsync::run(fn () => $stream->readAsync(10));
		self::assertFalse($future->isComplete());
		$stream->markRemoteClosed();
		self::assertSame('', $future->await());
	}

	public function testReadAsyncReturnsEmptyWhenMarkClosedEndsTheWait()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$future = TAsync::run(fn () => $stream->readAsync(10));
		$stream->markClosed();                                 // nghttp2 closed the stream (peer reset, completion, session close)
		self::assertSame('', $future->await());
		self::assertFalse($stream->isWritable());
	}

	public function testReadAsyncThrowsWhenCloseEndsTheWait()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$future = TAsync::run(fn () => $stream->readAsync(10));
		$stream->close();
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('detached or closed');
		$future->await();
	}

	public function testReadAsyncThrowsTheCancelledTokenWhileWaiting()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$token = new TCancellationToken();
		$future = TAsync::run(fn () => $stream->readAsync(10, $token));
		self::assertFalse($future->isComplete());
		$token->cancel();
		try {
			$future->await();
			self::fail('The cancelled token did not end the wait.');
		} catch (TCancelledException $e) {
			self::assertSame($token->getReason(), $e);
		}
		// The waiter slot is released: a later wait on the stream works and sees the bytes.
		$future = TAsync::run(fn () => $stream->readAsync(10));
		$stream->pushIncoming('after');
		self::assertSame('after', $future->await());
	}

	public function testReadAsyncThrowsATokenCancelledBeforeTheWait()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$token = new TCancellationToken();
		$token->cancel();
		$this->expectException(TCancelledException::class);
		$stream->readAsync(10, $token);
	}

	public function testReadAsyncTokenSubscriptionIsReleasedAfterANormalWake()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$token = new TCancellationToken();
		$future = TAsync::run(fn () => $stream->readAsync(10, $token));
		$stream->pushIncoming('bytes');
		self::assertSame('bytes', $future->await());
		$token->cancel();                                      // no suspension is left to throw into
		self::assertTrue($token->isCancelled());
		self::assertSame('', $stream->read(10));
	}

	public function testASecondConcurrentWaitThrows()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$first = TAsync::run(fn () => $stream->readAsync(10));
		$second = TAsync::run(fn () => $stream->readAsync(10));
		self::assertTrue($second->isRejected(), 'The second fiber is refused at once.');
		try {
			$second->await();
			self::fail('The second wait did not throw.');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('already waits', $e->getMessage());
		}
		$stream->pushIncoming('bytes');
		self::assertSame('bytes', $first->await(), 'The first waiter is unaffected.');
	}

	public function testReadAsyncOnADetachedStreamThrows()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->close();
		$this->expectException(\RuntimeException::class);
		$stream->readAsync(10);
	}

	public function testReadAsyncNegativeLengthThrows()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('negative');
		$stream->readAsync(-1);
	}

	public function testWaitReadableReturnsAtOnceWhenNothingToWaitFor()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->pushIncoming('x');
		$stream->waitReadable();                               // bytes are buffered
		self::assertSame('x', $stream->read(1));
		$stream->markRemoteClosed();
		$stream->waitReadable();                               // end of stream
		self::assertTrue($stream->eof());
		$stream->close();
		$this->expectException(\RuntimeException::class);
		$stream->waitReadable();
	}

	// =========================================================================
	// writeAsync() / waitWritable(): backpressure on the PRADO 4.4 event loop
	// =========================================================================

	public function testWriteAsyncReturnsAtOnceAtOrBelowTheMark()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->setWriteHighWaterMark(10);
		self::assertSame(10, $stream->getWriteHighWaterMark());
		self::assertSame(4, $stream->writeAsync('abcd'), 'Below the mark: no fiber, no loop.');
		self::assertSame(6, $stream->writeAsync('efghij'), 'At the mark still returns at once.');
		self::assertSame(10, $stream->getOutgoingLength());
		$stream->setWriteHighWaterMark(-5);
		self::assertSame(0, $stream->getWriteHighWaterMark(), 'A negative mark is zero.');
		$stream->setWriteHighWaterMark(null);
		self::assertNull($stream->getWriteHighWaterMark(), 'Null returns to the peer window.');
	}

	public function testWriteAsyncWaitsUntilDrainOutgoingBringsTheQueueToTheMark()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->setWriteHighWaterMark(4);
		$future = TAsync::run(fn () => $stream->writeAsync('0123456789'));
		self::assertFalse($future->isComplete(), 'The bytes are queued in full and the fiber waits.');
		self::assertSame(10, $stream->getOutgoingLength());
		self::assertSame('012', $stream->drainOutgoing(3));
		TAsync::sleep(0.001);                                  // the loop resumes the fiber: 7 > 4, it waits again
		self::assertFalse($future->isComplete());
		self::assertSame('345', $stream->drainOutgoing(3));
		self::assertSame(10, $future->await(), 'At the mark: the write returns its byte count.');
		self::assertSame(4, $stream->getOutgoingLength());
	}

	public function testWriteAsyncDefaultsToThePeerWindow()
	{
		$this->requireAsync();
		$session = $this->createMock(TH2Session::class);
		$session->method('getStreamRemoteWindowSize')->willReturn(8);
		$stream = new TH2Stream($session, 1, []);
		self::assertNull($stream->getWriteHighWaterMark());
		self::assertSame(8, $stream->writeAsync('12345678'), 'Within the window: returns at once.');
		$future = TAsync::run(fn () => $stream->writeAsync('9'));
		self::assertFalse($future->isComplete(), 'Beyond the window: waits.');
		$stream->drainOutgoing(1);
		self::assertSame(1, $future->await());
	}

	public function testWriteAsyncTreatsAnUnknownStreamWindowAsZero()
	{
		$this->requireAsync();
		$session = $this->createMock(TH2Session::class);
		$session->method('getStreamRemoteWindowSize')->willReturn(-1);   // nghttp2 no longer has the stream
		$stream = new TH2Stream($session, 1, []);
		$future = TAsync::run(fn () => $stream->writeAsync('x'));
		self::assertFalse($future->isComplete());
		$stream->drainOutgoing(1);
		self::assertSame(1, $future->await());
	}

	public function testWriteAsyncTreatsAClosedSessionWindowAsZero()
	{
		$this->requireAsync();
		$session = $this->createMock(TH2Session::class);
		$session->method('getStreamRemoteWindowSize')->willThrowException(new THttp2Exception('http2_session_closed'));
		$stream = new TH2Stream($session, 1, []);
		$future = TAsync::run(fn () => $stream->writeAsync('x'));
		self::assertFalse($future->isComplete());
		$stream->markClosed();                                 // the session closed its streams
		$this->expectException(\RuntimeException::class);
		$future->await();
	}

	public function testWriteAsyncThrowsWhenTheStreamClosesWhileWaiting()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->setWriteHighWaterMark(0);
		$future = TAsync::run(fn () => $stream->writeAsync('x'));
		self::assertFalse($future->isComplete());
		$stream->markClosed();                                 // nghttp2 closed the stream (peer reset)
		try {
			$future->await();
			self::fail('The close did not end the wait.');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('local side is closed', $e->getMessage());
		}
	}

	public function testWriteAsyncThrowsWhenAnotherFiberEndsTheLocalSide()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->setWriteHighWaterMark(0);
		$future = TAsync::run(fn () => $stream->writeAsync('x'));
		$stream->markLocalClosed();                            // the body was ended elsewhere
		try {
			$future->await();
			self::fail('The local close did not end the wait.');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('local side is closed', $e->getMessage());
		}
		self::assertSame(1, $stream->getOutgoingLength(), 'The queued byte is still flushed before END_STREAM.');
	}

	public function testWriteAsyncThrowsTheCancelledTokenAndKeepsTheBytesQueued()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->setWriteHighWaterMark(0);
		$token = new TCancellationToken();
		$future = TAsync::run(fn () => $stream->writeAsync('kept', $token));
		self::assertFalse($future->isComplete());
		$token->cancel();
		try {
			$future->await();
			self::fail('The cancelled token did not end the wait.');
		} catch (TCancelledException $e) {
			self::assertSame(4, $stream->getOutgoingLength());
		}
		self::assertSame('kept', $stream->drainOutgoing(10), 'The bytes stay queued for the data provider.');
		$this->expectException(TCancelledException::class);
		$stream->waitWritable($token);                         // a token cancelled before the wait throws at once
	}

	public function testASecondConcurrentWriteWaitThrows()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->setWriteHighWaterMark(0);
		$first = TAsync::run(fn () => $stream->writeAsync('a'));
		$second = TAsync::run(fn () => $stream->writeAsync('b'));
		self::assertTrue($second->isRejected(), 'The second fiber is refused at once.');
		try {
			$second->await();
			self::fail('The second wait did not throw.');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('already waits', $e->getMessage());
		}
		self::assertSame(2, $stream->getOutgoingLength(), 'Both writes queued; only the wait was refused.');
		$stream->drainOutgoing(2);
		self::assertSame(1, $first->await(), 'The first waiter is unaffected.');
	}

	public function testWriteAsyncOnANonWritableStreamThrowsWithoutQueuing()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->markLocalClosed();
		try {
			$stream->writeAsync('x');
			self::fail('A write after the local close did not throw.');
		} catch (\RuntimeException $e) {
			self::assertSame(0, $stream->getOutgoingLength());
		}
		$stream->close();
		$this->expectException(\RuntimeException::class);
		$stream->waitWritable();
	}

	public function testWaitWritableReturnsAtOnceWhenNothingToWaitFor()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->setWriteHighWaterMark(0);
		$stream->waitWritable();                               // nothing queued
		$stream->write('x');
		$stream->drainOutgoing(1);
		$stream->waitWritable();                               // drained
		self::assertSame(0, $stream->getOutgoingLength());
	}

	public function testAReadWaitAndAWriteWaitCoexist()
	{
		$this->requireAsync();
		$stream = $this->stream();
		$stream->setWriteHighWaterMark(0);
		$reader = TAsync::run(fn () => $stream->readAsync(10));
		$writer = TAsync::run(fn () => $stream->writeAsync('out'));
		self::assertFalse($reader->isComplete());
		self::assertFalse($writer->isComplete());
		$stream->drainOutgoing(3);
		self::assertSame(3, $writer->await());
		self::assertFalse($reader->isComplete(), 'The write wake leaves the read wait alone.');
		$stream->pushIncoming('in');
		self::assertSame('in', $reader->await());
	}

	// =========================================================================
	// awaitResponse(): a client fiber waits for the final response headers
	// =========================================================================

	public function testAwaitResponseReturnsAtOnceWithAFinalResponse()
	{
		$this->requireAsync();
		$stream = $this->stream([':method' => 'GET']);
		self::assertFalse($stream->hasFinalResponse());
		$stream->mergeHeaders([':status' => '200', 'content-type' => 'text/plain']);
		self::assertTrue($stream->hasFinalResponse());
		self::assertSame([':method' => 'GET', ':status' => '200', 'content-type' => 'text/plain'], $stream->awaitResponse());
	}

	public function testAwaitResponseWaitsThroughAnInformationalResponse()
	{
		$this->requireAsync();
		$stream = $this->stream([':method' => 'GET']);
		$future = TAsync::run(fn () => $stream->awaitResponse());
		self::assertFalse($future->isComplete());
		$stream->mergeHeaders([':status' => '103', 'link' => '</style.css>; rel=preload']);
		TAsync::sleep(0.001);                                  // the loop resumes the fiber: a 1xx keeps it waiting
		self::assertFalse($future->isComplete(), 'A 1xx keeps the wait going.');
		self::assertFalse($stream->hasFinalResponse());
		$stream->mergeHeaders([':status' => '200']);
		$headers = $future->await();
		self::assertSame('200', $headers[':status']);
		self::assertSame('</style.css>; rel=preload', $headers['link'], 'The informational headers were merged too.');
	}

	public function testAwaitResponseThrowsWhenThePeerEndsTheStreamFirst()
	{
		$this->requireAsync();
		$stream = $this->stream([':method' => 'GET']);
		$future = TAsync::run(fn () => $stream->awaitResponse());
		$stream->markRemoteClosed();                           // the peer ended the stream without a response
		try {
			$future->await();
			self::fail('The end of stream did not end the wait.');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('before a final response', $e->getMessage());
		}
		$this->expectException(\RuntimeException::class);
		$stream->awaitResponse();                              // and at once from then on
	}

	public function testAwaitResponseThrowsWhenTheStreamClosesWhileWaiting()
	{
		$this->requireAsync();
		$stream = $this->stream([':method' => 'GET']);
		$byReset = TAsync::run(fn () => $stream->awaitResponse());
		$stream->markClosed();                                 // nghttp2 closed the stream (a reset)
		try {
			$byReset->await();                                 // the loop resumes the fiber, which re-checks and throws
			self::fail('The reset did not end the wait.');
		} catch (\RuntimeException $e) {
			self::assertTrue($byReset->isRejected());
		}
		$other = $this->stream([':method' => 'GET']);
		$byClose = TAsync::run(fn () => $other->awaitResponse());
		$other->close();                                       // closed locally while waiting
		$this->expectException(\RuntimeException::class);
		$byClose->await();
	}

	public function testAwaitResponseThrowsTheCancelledToken()
	{
		$this->requireAsync();
		$stream = $this->stream([':method' => 'GET']);
		$token = new TCancellationToken();
		$future = TAsync::run(fn () => $stream->awaitResponse($token));
		$token->cancel();
		try {
			$future->await();
			self::fail('The cancelled token did not end the wait.');
		} catch (TCancelledException $e) {
			self::assertFalse($stream->hasFinalResponse());
		}
		$stream->mergeHeaders([':status' => '200']);
		self::assertSame('200', $stream->awaitResponse()[':status'], 'The stream is unaffected by the cancelled wait.');
	}

	/** Skips the test when the PRADO 4.4 async API is absent. */
	private function requireAsync(): void
	{
		if (!class_exists(TAsync::class)) {
			$this->markTestSkipped('The PRADO async API (Prado\\IO\\Async\\TAsync) is not available.');
		}
	}

	/**
	 * A stream exposing its raw buffers, to observe compaction and dropped bytes.
	 */
	private function spyStream()
	{
		$session = $this->createMock(TH2Session::class);
		return new class ($session, 1) extends TH2Stream {
			public function rawIncoming(): string
			{
				return $this->getIncomingDirect();
			}

			public function rawOutgoing(): string
			{
				return $this->getOutgoingDirect();
			}
		};
	}

	/** @return string $length bytes whose every 4-byte word is its own index, so a reorder or loss is detectable. */
	private function pattern(int $length): string
	{
		$words = [];
		for ($i = 0, $n = intdiv($length, 4); $i < $n; $i++) {
			$words[] = pack('N', $i);
		}
		return implode('', $words);
	}
}
