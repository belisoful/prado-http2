<?php

use Prado\IO\Http2\TH2Session;
use Prado\IO\Http2\TH2Stream;

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
}
