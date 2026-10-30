<?php

/**
 * TH2Stream class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-http2
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Http2;

use Prado\Exceptions\TCancelledException;
use Prado\IO\Async\TAsync;
use Prado\IO\Async\TCancellationToken;
use Prado\TComponent;
use Psr\Http\Message\StreamInterface;
use Revolt\EventLoop\Suspension;

/**
 * TH2Stream class.
 *
 * One HTTP/2 stream as a duplex {@see StreamInterface}.  Bytes received in DATA frames are
 * buffered for {@see read()}/{@see getContents()}; bytes written with {@see write()} are queued
 * and drained into outgoing DATA frames by the owning {@see TH2Session}'s data provider, which
 * resumes the stream so nghttp2 pulls the new bytes.
 *
 * The stream carries the request or response {@see getHeaders() headers}, including the HTTP/2
 * pseudo-headers (`:method`, `:path`, `:protocol`, `:status`, ...).  It is not seekable; a seek
 * throws.  Reads are non-blocking: {@see read()} returns the buffered bytes, or '' when none
 * have arrived yet.  The stream is at {@see eof()} once the peer half-closes and the buffer is
 * drained.  {@see markLocalClosed()} finishes a finite body: the queued bytes flush and the
 * stream then ends (END_STREAM).  {@see close()} discards the buffers, detaches the stream, and
 * ends it on the wire: a peer that is still sending is cancelled (RST_STREAM CANCEL); otherwise
 * this side ends (END_STREAM).  Once nghttp2 closes the stream (peer reset, completion, or
 * session close) the session marks both directions closed: buffered bytes stay readable, a write
 * throws.  A {@see read()}, {@see getContents()}, or {@see write()} on a detached stream throws,
 * per PSR-7.
 *
 * {@see readAsync()} is the fiber-aware read: it returns buffered bytes, suspends the current
 * fiber on the {@see TAsync::getLoop() loop} while none are buffered and the peer is still
 * sending, and returns '' only at end of stream, as {@see \Prado\IO\Async\TAsyncStream::read()}
 * does.  {@see pushIncoming()} and the remote-close paths resume the waiting fiber through the
 * loop.  A worker fiber started with `TAsync::run()` from an `onRequest` handler consumes a
 * request body this way as it arrives.
 *
 * {@see writeAsync()} is the fiber-aware write with backpressure: it queues the bytes and
 * suspends the fiber while more than the {@see getWriteHighWaterMark() high-water mark} is
 * queued, until the data provider drains the queue into DATA frames.  The mark defaults to the
 * peer's remaining flow-control window for the stream, so the queue tracks what the peer can
 * accept.  {@see awaitResponse()} suspends a client fiber until the final response headers
 * arrive (a `:status` other than 1xx).  One fiber waits per condition; a second concurrent wait
 * of the same kind throws.
 *
 * State is self-encapsulated: every field is reached through a protected `get*Direct()`/
 * `set*Direct()` accessor (the byte buffers return by reference), so a subclass can intercept
 * any of it.  Each buffer is consumed from the front through an offset (`IncomingOffset`,
 * `OutgoingOffset`) and compacted once the consumed prefix is large, so draining a large body is
 * linear.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 * @see https://www.rfc-editor.org/rfc/rfc9113.html
 */
class TH2Stream extends TComponent implements StreamInterface
{
	/** @var string The wait kind of {@see waitReadable()}: incoming bytes or end of stream. */
	public const WAIT_READ = 'read';

	/** @var string The wait kind of {@see waitWritable()}: the outgoing queue at or below the high-water mark. */
	public const WAIT_WRITE = 'write';

	/** @var string The wait kind of {@see awaitResponse()}: the final response headers. */
	public const WAIT_RESPONSE = 'response';

	/** @var int The consumed prefix length at which a buffer is compacted (once it is at least half consumed). */
	private const COMPACT_THRESHOLD = 1048576;

	/** @var TH2Session The owning session. */
	private TH2Session $_session;

	/** @var int The HTTP/2 stream identifier. */
	private int $_streamId;

	/** @var array<string, string> The stream's headers, including pseudo-headers. */
	private array $_headers;

	/** @var string Bytes received from the peer, awaiting a read; the first {@see $_incomingOffset} bytes are consumed. */
	private string $_incoming = '';

	/** @var int The length of the consumed prefix of {@see $_incoming}. */
	private int $_incomingOffset = 0;

	/** @var string Bytes written locally, awaiting outgoing DATA frames; the first {@see $_outgoingOffset} bytes are sent. */
	private string $_outgoing = '';

	/** @var int The length of the consumed prefix of {@see $_outgoing}. */
	private int $_outgoingOffset = 0;

	/** @var bool Whether the peer has half-closed (no more incoming DATA). */
	private bool $_remoteClosed = false;

	/** @var bool Whether this side has finished writing (no more outgoing DATA). */
	private bool $_localClosed = false;

	/** @var bool Whether the stream has been closed or detached (unusable per PSR-7). */
	private bool $_detached = false;

	/** @var bool Whether a trailing header block is queued to send after the body. */
	private bool $_trailersPending = false;

	/** @var array<string, string> The trailing headers to send once the body finishes. */
	private array $_trailers = [];

	/** @var int The total number of bytes read, for {@see tell()}. */
	private int $_readPosition = 0;

	/** @var array<string, Suspension> The suspended fibers by wait kind ({@see WAIT_READ}, {@see WAIT_WRITE}, {@see WAIT_RESPONSE}). */
	private array $_waiters = [];

	/** @var ?int The queued outgoing byte count above which {@see writeAsync()} waits, or null for the peer's window. */
	private ?int $_writeHighWaterMark = null;

	/**
	 * @param TH2Session $session The owning session.
	 * @param int $streamId The HTTP/2 stream identifier.
	 * @param array<string, string> $headers The stream's headers, including pseudo-headers.
	 */
	public function __construct(TH2Session $session, int $streamId, array $headers = [])
	{
		$this->_session = $session;
		$this->_streamId = $streamId;
		$this->setHeadersDirect($headers);
		parent::__construct();
	}

	// =========================================================================
	// Self-Encapsulated Accessors
	// =========================================================================

	/**
	 * Returns the owning session.
	 * @return TH2Session The owning session.
	 */
	protected function getSessionDirect(): TH2Session
	{
		return $this->_session;
	}

	/**
	 * Returns the raw stream identifier.
	 * @return int The HTTP/2 stream identifier.
	 */
	protected function getStreamIdDirect(): int
	{
		return $this->_streamId;
	}

	/**
	 * Returns the raw header map.
	 * @return array<string, string> The headers.
	 */
	protected function getHeadersDirect(): array
	{
		return $this->_headers;
	}

	/**
	 * Sets the raw header map.
	 * @param array<string, string> $value The headers.
	 */
	protected function setHeadersDirect(array $value): void
	{
		$this->_headers = $value;
	}

	/**
	 * Returns the raw incoming buffer by reference, for in-place mutation.  Its first
	 * {@see getIncomingOffsetDirect()} bytes are already consumed.
	 * @return string The incoming bytes, by reference.
	 */
	protected function &getIncomingDirect(): string
	{
		return $this->_incoming;
	}

	/**
	 * Sets the raw incoming buffer and resets its consumed prefix.
	 * @param string $value The incoming bytes.
	 */
	protected function setIncomingDirect(string $value): void
	{
		$this->_incoming = $value;
		$this->_incomingOffset = 0;
	}

	/**
	 * Returns the length of the consumed prefix of the incoming buffer.
	 * @return int The consumed prefix length.
	 * @since 1.2.0
	 */
	protected function getIncomingOffsetDirect(): int
	{
		return $this->_incomingOffset;
	}

	/**
	 * Sets the length of the consumed prefix of the incoming buffer.
	 * @param int $value The consumed prefix length.
	 * @since 1.2.0
	 */
	protected function setIncomingOffsetDirect(int $value): void
	{
		$this->_incomingOffset = $value;
	}

	/**
	 * Returns the raw outgoing buffer by reference, for in-place mutation.  Its first
	 * {@see getOutgoingOffsetDirect()} bytes are already sent.
	 * @return string The outgoing bytes, by reference.
	 */
	protected function &getOutgoingDirect(): string
	{
		return $this->_outgoing;
	}

	/**
	 * Sets the raw outgoing buffer and resets its consumed prefix.
	 * @param string $value The outgoing bytes.
	 */
	protected function setOutgoingDirect(string $value): void
	{
		$this->_outgoing = $value;
		$this->_outgoingOffset = 0;
	}

	/**
	 * Returns the length of the consumed prefix of the outgoing buffer.
	 * @return int The consumed prefix length.
	 * @since 1.2.0
	 */
	protected function getOutgoingOffsetDirect(): int
	{
		return $this->_outgoingOffset;
	}

	/**
	 * Sets the length of the consumed prefix of the outgoing buffer.
	 * @param int $value The consumed prefix length.
	 * @since 1.2.0
	 */
	protected function setOutgoingOffsetDirect(int $value): void
	{
		$this->_outgoingOffset = $value;
	}

	/**
	 * Returns the raw remote-closed flag.
	 * @return bool Whether the peer has half-closed.
	 */
	protected function getRemoteClosedDirect(): bool
	{
		return $this->_remoteClosed;
	}

	/**
	 * Sets the raw remote-closed flag.
	 * @param bool $value Whether the peer has half-closed.
	 */
	protected function setRemoteClosedDirect(bool $value): void
	{
		$this->_remoteClosed = $value;
	}

	/**
	 * Returns the raw local-closed flag.
	 * @return bool Whether this side has finished writing.
	 */
	protected function getLocalClosedDirect(): bool
	{
		return $this->_localClosed;
	}

	/**
	 * Sets the raw local-closed flag.
	 * @param bool $value Whether this side has finished writing.
	 */
	protected function setLocalClosedDirect(bool $value): void
	{
		$this->_localClosed = $value;
	}

	/**
	 * Returns the raw detached flag.
	 * @return bool Whether the stream has been closed or detached.
	 */
	protected function getDetachedDirect(): bool
	{
		return $this->_detached;
	}

	/**
	 * Sets the raw detached flag.
	 * @param bool $value Whether the stream has been closed or detached.
	 */
	protected function setDetachedDirect(bool $value): void
	{
		$this->_detached = $value;
	}

	/**
	 * Returns the raw trailers-pending flag.
	 * @return bool Whether a trailing header block is queued.
	 */
	protected function getTrailersPendingDirect(): bool
	{
		return $this->_trailersPending;
	}

	/**
	 * Sets the raw trailers-pending flag.
	 * @param bool $value Whether a trailing header block is queued.
	 */
	protected function setTrailersPendingDirect(bool $value): void
	{
		$this->_trailersPending = $value;
	}

	/**
	 * Returns the raw trailing headers.
	 * @return array<string, string> The queued trailing headers.
	 */
	protected function getTrailersDirect(): array
	{
		return $this->_trailers;
	}

	/**
	 * Sets the raw trailing headers.
	 * @param array<string, string> $value The trailing headers.
	 */
	protected function setTrailersDirect(array $value): void
	{
		$this->_trailers = $value;
	}

	/**
	 * Returns the raw read position.
	 * @return int The total bytes read.
	 */
	protected function getReadPositionDirect(): int
	{
		return $this->_readPosition;
	}

	/**
	 * Sets the raw read position.
	 * @param int $value The total bytes read.
	 */
	protected function setReadPositionDirect(int $value): void
	{
		$this->_readPosition = $value;
	}

	/**
	 * Returns the raw suspension of the fiber waiting for a condition.
	 * @param string $kind The wait kind: {@see WAIT_READ}, {@see WAIT_WRITE}, or {@see WAIT_RESPONSE}.
	 * @return ?Suspension The waiting fiber's suspension, or null when no fiber waits.
	 * @since 1.2.1
	 */
	protected function getWaiterDirect(string $kind = self::WAIT_READ): ?Suspension
	{
		return $this->_waiters[$kind] ?? null;
	}

	/**
	 * Sets the raw suspension of the fiber waiting for a condition.
	 * @param ?Suspension $value The waiting fiber's suspension, or null when no fiber waits.
	 * @param string $kind The wait kind: {@see WAIT_READ}, {@see WAIT_WRITE}, or {@see WAIT_RESPONSE}.
	 * @since 1.2.1
	 */
	protected function setWaiterDirect(?Suspension $value, string $kind = self::WAIT_READ): void
	{
		if ($value === null) {
			unset($this->_waiters[$kind]);
		} else {
			$this->_waiters[$kind] = $value;
		}
	}

	/**
	 * Returns the raw write high-water mark.
	 * @return ?int The mark in bytes, or null for the peer's window.
	 * @since 1.2.1
	 */
	protected function getWriteHighWaterMarkDirect(): ?int
	{
		return $this->_writeHighWaterMark;
	}

	/**
	 * Sets the raw write high-water mark.
	 * @param ?int $value The mark in bytes, or null for the peer's window.
	 * @since 1.2.1
	 */
	protected function setWriteHighWaterMarkDirect(?int $value): void
	{
		$this->_writeHighWaterMark = $value;
	}

	// =========================================================================
	// Properties
	// =========================================================================

	/** @return int The HTTP/2 stream identifier. */
	public function getStreamId(): int
	{
		return $this->getStreamIdDirect();
	}

	/** @return array<string, string> The stream's headers, including pseudo-headers. */
	public function getHeaders(): array
	{
		return $this->getHeadersDirect();
	}

	/**
	 * Returns a single header value (pseudo-headers included), or null when absent.
	 * @param string $name The header name (e.g. ':method', ':protocol').
	 * @return ?string The header value, or null.
	 */
	public function getHeader(string $name): ?string
	{
		return $this->getHeadersDirect()[$name] ?? null;
	}

	/**
	 * Indicates whether the final response headers are present: a `:status` other than 1xx.  A
	 * client stream gains them when the server's final response arrives; a server stream never has
	 * them.
	 * @return bool Whether a final response `:status` is present.
	 * @since 1.2.1
	 */
	public function hasFinalResponse(): bool
	{
		$status = $this->getHeader(':status');
		return $status !== null && $status !== '' && $status[0] !== '1';
	}

	/**
	 * Returns the queued outgoing byte count above which {@see writeAsync()} waits.  Null means the
	 * peer's remaining flow-control window for the stream
	 * ({@see TH2Session::getStreamRemoteWindowSize()}), so the queue holds what the peer can accept.
	 * @return ?int The mark in bytes, or null for the peer's window.
	 * @since 1.2.1
	 */
	public function getWriteHighWaterMark(): ?int
	{
		return $this->getWriteHighWaterMarkDirect();
	}

	/**
	 * Sets the queued outgoing byte count above which {@see writeAsync()} waits.  A negative value
	 * is treated as zero.
	 * @param ?int $value The mark in bytes, or null for the peer's window.
	 * @since 1.2.1
	 */
	public function setWriteHighWaterMark(?int $value): void
	{
		$this->setWriteHighWaterMarkDirect($value === null ? null : max(0, $value));
	}

	/**
	 * Returns the number of outgoing bytes queued and not yet drained into DATA frames.
	 * @return int The queued byte count.
	 * @since 1.2.1
	 */
	public function getOutgoingLength(): int
	{
		return strlen($this->getOutgoingDirect()) - $this->getOutgoingOffsetDirect();
	}

	// =========================================================================
	// Session Plumbing
	// =========================================================================

	/**
	 * Appends received DATA bytes to the incoming buffer (called by {@see TH2Session}) and wakes a
	 * fiber waiting in {@see readAsync()}.  A detached stream drops them: nothing can read them.
	 * @param string $bytes The received bytes.
	 */
	public function pushIncoming(string $bytes): void
	{
		if ($this->getDetachedDirect()) {
			return;
		}
		$incoming = &$this->getIncomingDirect();
		$incoming .= $bytes;
		if ($bytes !== '') {
			$this->wakeWaiter(self::WAIT_READ);
		}
	}

	/**
	 * Removes and returns up to $length queued outgoing bytes (called by the data provider) and
	 * wakes a fiber waiting in {@see writeAsync()}, which re-checks the queue against the mark.
	 * @param int $length The maximum bytes to take.
	 * @return string The dequeued bytes ('' when none are queued).
	 */
	public function drainOutgoing(int $length): string
	{
		if (!$this->hasOutgoing() || $length <= 0) {
			return '';
		}
		$outgoing = &$this->getOutgoingDirect();
		$offset = $this->getOutgoingOffsetDirect();
		$bytes = substr($outgoing, $offset, $length);
		$this->setOutgoingOffsetDirect(self::advance($outgoing, $offset + strlen($bytes)));
		$this->wakeWaiter(self::WAIT_WRITE);
		return $bytes;
	}

	/** @return bool Whether outgoing bytes are queued for sending. */
	public function hasOutgoing(): bool
	{
		return strlen($this->getOutgoingDirect()) > $this->getOutgoingOffsetDirect();
	}

	/** @return bool Whether received bytes are buffered, awaiting a read. */
	private function hasIncoming(): bool
	{
		return strlen($this->getIncomingDirect()) > $this->getIncomingOffsetDirect();
	}

	/**
	 * Moves a buffer's consumed prefix to $offset.  A fully consumed buffer is emptied; a buffer whose
	 * consumed prefix is at least {@see COMPACT_THRESHOLD} and at least half of it is compacted.  Each
	 * byte is copied at most a bounded number of times, so draining stays linear.
	 * @param string &$buffer The buffer, by reference.
	 * @param int $offset The new consumed prefix length.
	 * @return int The consumed prefix length after compaction.
	 */
	private static function advance(string &$buffer, int $offset): int
	{
		if ($offset >= strlen($buffer)) {
			$buffer = '';
			return 0;
		}
		if ($offset >= self::COMPACT_THRESHOLD && $offset * 2 >= strlen($buffer)) {
			$buffer = substr($buffer, $offset);
			return 0;
		}
		return $offset;
	}

	/** @return bool Whether this side has finished writing. */
	public function isLocalClosed(): bool
	{
		return $this->getLocalClosedDirect();
	}

	/**
	 * Marks the peer as half-closed; no further incoming DATA arrives.  Wakes a waiting
	 * {@see readAsync()} and {@see awaitResponse()}.
	 */
	public function markRemoteClosed(): void
	{
		$this->setRemoteClosedDirect(true);
		$this->wakeWaiter(self::WAIT_READ);
		$this->wakeWaiter(self::WAIT_RESPONSE);
	}

	/**
	 * Marks both directions closed without touching the session: nghttp2 no longer has the stream
	 * (the peer reset it, it completed, or the session closed).  Buffered incoming bytes stay
	 * readable until drained; a {@see write()} throws.  Called by {@see TH2Session}.
	 * @since 1.1.0
	 */
	public function markClosed(): void
	{
		$this->setRemoteClosedDirect(true);
		$this->setLocalClosedDirect(true);
		$this->wakeAllWaiters();
	}

	/**
	 * Marks this side as done writing: the data provider sends any queued outgoing bytes and then
	 * ends the stream (END_STREAM).  Unlike {@see close()}, the queued buffer is preserved and
	 * still flushed.  Use this to finish a finite response or request body.
	 *
	 * The stream is resumed so nghttp2 re-arms its data provider: a stream whose provider was
	 * already deferred (an open stream with nothing queued, e.g. a server `respond()` then
	 * `send()` with no body) would otherwise never emit END_STREAM.
	 */
	public function markLocalClosed(): void
	{
		$this->setLocalClosedDirect(true);
		$this->wakeWaiter(self::WAIT_WRITE);
		$this->getSessionDirect()->resumeStream($this->getStreamIdDirect());
	}

	/** @return bool Whether a trailing header block is queued to send after the body. */
	public function hasTrailersPending(): bool
	{
		return $this->getTrailersPendingDirect();
	}

	/**
	 * Returns the queued trailing headers and clears the pending flag.  Called by the session's
	 * data provider once the body finishes, to submit the trailers exactly once.
	 * @return array<string, string> The trailing headers.
	 */
	public function consumeTrailers(): array
	{
		$this->setTrailersPendingDirect(false);
		return $this->getTrailersDirect();
	}

	/**
	 * Finishes the body with a trailing header block (HTTP/2 trailers): the queued bytes flush as
	 * DATA, then the trailers are sent as a HEADERS frame with END_STREAM.  Trailers carry no
	 * pseudo-headers (no `:status`).  Call after {@see write()}ing the body, instead of
	 * {@see markLocalClosed()}.  The trailers are submitted by the data provider once the body has
	 * drained, as nghttp2 requires.
	 * @param array<string, string> $headers The trailing header name => value pairs.
	 * @throws \RuntimeException When this side already finished (closed, detached, or local-closed):
	 *   the body has ended, so nothing can carry the trailers.
	 */
	public function sendTrailers(array $headers): void
	{
		if ($this->getDetachedDirect() || $this->getLocalClosedDirect()) {
			throw new \RuntimeException('Cannot send trailers on an HTTP/2 stream whose local side is closed.');
		}
		$this->setTrailersDirect($headers);
		$this->setTrailersPendingDirect(true);
		$this->setLocalClosedDirect(true);
		$this->getSessionDirect()->resumeStream($this->getStreamIdDirect());
	}

	/**
	 * Cancels this stream (RST_STREAM) on the session, leaving the connection open for others.
	 * @param int $errorCode A {@see TNgHttp2} error code. Default {@see TNgHttp2::CANCEL}.
	 */
	public function cancel(int $errorCode = TNgHttp2::CANCEL): void
	{
		$this->getSessionDirect()->resetStream($this->getStreamIdDirect(), $errorCode);
	}

	/**
	 * Merges additional headers into the stream (e.g. response headers on the client side); a name
	 * already present takes the new value.  Keys are kept as given, so a digit-only field name such
	 * as `123` survives (PHP stores it as an integer key).  Wakes a waiting {@see awaitResponse()},
	 * which checks whether the merged block is the final response.
	 * @param array<string, string> $headers The headers to merge.
	 */
	public function mergeHeaders(array $headers): void
	{
		$this->setHeadersDirect(array_replace($this->getHeadersDirect(), $headers));
		$this->wakeWaiter(self::WAIT_RESPONSE);
	}

	// =========================================================================
	// Fiber-aware reads
	// =========================================================================

	/**
	 * Reads up to $length buffered incoming bytes, suspending the current fiber on the
	 * {@see TAsync::getLoop() loop} while none are buffered and the peer is still sending.  Returns
	 * '' only at end of stream (the peer half-closed and the buffer is drained), as
	 * {@see \Prado\IO\Async\TAsyncStream::read()} does; {@see read()} returns '' for "nothing
	 * yet" as well.  The wait ends when {@see pushIncoming()} buffers bytes, when the remote side
	 * closes ({@see markRemoteClosed()}, {@see markClosed()}, {@see close()}), or when $token is
	 * cancelled.  Call it from a fiber the loop resumes, such as one started with `TAsync::run()`
	 * from an `onRequest` handler, never from inside a session event handler: a wait there leaves
	 * nghttp2 mid-call and the loop's next `receive()` throws `http2_session_busy`.  One fiber waits
	 * per stream.
	 * @param int $length The maximum number of bytes to return.
	 * @param ?TCancellationToken $token A token whose cancellation ends the wait.
	 * @throws TCancelledException When the token is cancelled while waiting.
	 * @throws \RuntimeException When the stream is detached/closed, $length is negative, or another
	 *   fiber already waits on the stream.
	 * @return string The bytes read, or '' at end of stream.
	 * @since 1.2.1
	 */
	public function readAsync(int $length, ?TCancellationToken $token = null): string
	{
		if ($length <= 0) {
			return $this->read($length);
		}
		while (true) {
			$bytes = $this->read($length);
			if ($bytes !== '' || $this->getRemoteClosedDirect()) {
				return $bytes;
			}
			$this->waitReadable($token);
		}
	}

	/**
	 * Suspends the current fiber until incoming bytes are buffered or the remote side closes.
	 * Returns at once when either already holds.  The bare wait behind {@see readAsync()}, for code
	 * that reads with {@see read()} or {@see getContents()} itself.
	 * @param ?TCancellationToken $token A token whose cancellation ends the wait.
	 * @throws TCancelledException When the token is cancelled while waiting, or was cancelled before.
	 * @throws \RuntimeException When the stream is detached/closed, or another fiber already waits
	 *   on the stream.
	 * @since 1.2.1
	 */
	public function waitReadable(?TCancellationToken $token = null): void
	{
		$token?->throwIfCancelled();
		if ($this->getDetachedDirect()) {
			throw new \RuntimeException('Cannot read from a detached or closed HTTP/2 stream.');
		}
		if ($this->hasIncoming() || $this->getRemoteClosedDirect()) {
			return;
		}
		$this->suspendOn(self::WAIT_READ, $token);
	}

	// =========================================================================
	// Fiber-aware writes
	// =========================================================================

	/**
	 * Queues bytes for outgoing DATA frames as {@see write()} does, then suspends the current fiber
	 * on the {@see TAsync::getLoop() loop} while more than the {@see getWriteHighWaterMark()
	 * high-water mark} is queued.  The data provider drains the queue into DATA frames as the peer's
	 * flow-control window allows, and each {@see drainOutgoing()} resumes the fiber through the loop
	 * to re-check the queue.  The bytes are queued in full before the wait, so the queue holds at
	 * most the mark plus one write, and a write never stalls on a closed window with nothing queued.
	 * Call it from a fiber the loop resumes, never from inside a session event handler (a wait
	 * there is the `http2_session_busy` case).  One fiber waits to write per stream.
	 * @param string $string The bytes to send.
	 * @param ?TCancellationToken $token A token whose cancellation ends the wait; the bytes stay queued.
	 * @throws TCancelledException When the token is cancelled while waiting.
	 * @throws \RuntimeException When the stream is not writable (closed, detached, or local-closed),
	 *   when it closes while the fiber waits, or when another fiber already waits to write.
	 * @return int The number of bytes queued.
	 * @since 1.2.1
	 */
	public function writeAsync(string $string, ?TCancellationToken $token = null): int
	{
		$count = $this->write($string);
		$this->waitWritable($token);
		return $count;
	}

	/**
	 * Suspends the current fiber until the queued outgoing bytes are at or below the
	 * {@see getWriteHighWaterMark() high-water mark}.  Returns at once when they already are.  The
	 * bare wait behind {@see writeAsync()}, for code that queues with {@see write()} itself.
	 * @param ?TCancellationToken $token A token whose cancellation ends the wait.
	 * @throws TCancelledException When the token is cancelled while waiting, or was cancelled before.
	 * @throws \RuntimeException When the stream is not writable (closed, detached, or local-closed),
	 *   when it closes while the fiber waits, or when another fiber already waits to write.
	 * @since 1.2.1
	 */
	public function waitWritable(?TCancellationToken $token = null): void
	{
		$token?->throwIfCancelled();
		while (true) {
			if (!$this->isWritable()) {
				throw new \RuntimeException('Cannot write to a non-writable HTTP/2 stream; the local side is closed.');
			}
			if ($this->getOutgoingLength() <= $this->effectiveWriteHighWaterMark()) {
				return;
			}
			$this->suspendOn(self::WAIT_WRITE, $token);
		}
	}

	/**
	 * Returns the high-water mark in effect: the set mark, else the peer's remaining flow-control
	 * window for the stream (zero once the session is closed or nghttp2 no longer has the stream).
	 * @return int The mark in bytes.
	 */
	private function effectiveWriteHighWaterMark(): int
	{
		$mark = $this->getWriteHighWaterMarkDirect();
		if ($mark !== null) {
			return $mark;
		}
		try {
			return max(0, $this->getSessionDirect()->getStreamRemoteWindowSize($this->getStreamIdDirect()));
		} catch (THttp2Exception $e) {
			return 0;
		}
	}

	// =========================================================================
	// Fiber-aware response wait
	// =========================================================================

	/**
	 * Returns the headers once the final response headers are present ({@see hasFinalResponse()}),
	 * suspending the current fiber on the {@see TAsync::getLoop() loop} until they arrive on a
	 * client stream.  A 1xx informational response keeps the wait going; the body then follows
	 * through {@see readAsync()}.  Returns at once when a final response is already present.
	 * Call it from a fiber the loop resumes, never from inside a session event handler.  One fiber
	 * waits for the response per stream.  A server stream has no response, so the wait ends only
	 * with the stream.
	 * @param ?TCancellationToken $token A token whose cancellation ends the wait.
	 * @throws TCancelledException When the token is cancelled while waiting, or was cancelled before.
	 * @throws \RuntimeException When the stream ends (reset, session close, or the peer finishing
	 *   without one) before a final response, or another fiber already waits for it.
	 * @return array<string, string> The headers, including the response pseudo-headers.
	 * @since 1.2.1
	 */
	public function awaitResponse(?TCancellationToken $token = null): array
	{
		$token?->throwIfCancelled();
		while (true) {
			if ($this->hasFinalResponse()) {
				return $this->getHeadersDirect();
			}
			if ($this->getDetachedDirect() || $this->getRemoteClosedDirect()) {
				throw new \RuntimeException('The HTTP/2 stream ended before a final response arrived.');
			}
			$this->suspendOn(self::WAIT_RESPONSE, $token);
		}
	}

	// =========================================================================
	// Wait plumbing
	// =========================================================================

	/**
	 * Suspends the current fiber on the loop until {@see wakeWaiter()} is called for the kind or
	 * the token is cancelled.  One fiber waits per kind.
	 * @param string $kind The wait kind: {@see WAIT_READ}, {@see WAIT_WRITE}, or {@see WAIT_RESPONSE}.
	 * @param ?TCancellationToken $token A token whose cancellation ends the wait.
	 * @throws TCancelledException When the token is cancelled while waiting.
	 * @throws \RuntimeException When another fiber already waits for the kind.
	 */
	private function suspendOn(string $kind, ?TCancellationToken $token): void
	{
		if ($this->getWaiterDirect($kind) !== null) {
			throw new \RuntimeException("Another fiber already waits on this HTTP/2 stream ($kind).");
		}
		$suspension = TAsync::getLoop()->suspension();
		$this->setWaiterDirect($suspension, $kind);
		$subscription = $token?->subscribe(function (TCancelledException $reason) use ($suspension, $kind): void {
			if ($this->getWaiterDirect($kind) === $suspension) {
				$this->setWaiterDirect(null, $kind);
				$suspension->throw($reason);
			}
		});
		try {
			$suspension->suspend();
		} finally {
			if ($this->getWaiterDirect($kind) === $suspension) {
				$this->setWaiterDirect(null, $kind);
			}
			if ($subscription !== null) {
				$token->unsubscribe($subscription);
			}
		}
	}

	/**
	 * Resumes the fiber waiting for a kind, if any.  The loop resumes it on its next iteration, so
	 * a wake from inside an nghttp2 callback switches no fiber there.
	 * @param string $kind The wait kind: {@see WAIT_READ}, {@see WAIT_WRITE}, or {@see WAIT_RESPONSE}.
	 */
	private function wakeWaiter(string $kind): void
	{
		$waiter = $this->getWaiterDirect($kind);
		if ($waiter !== null) {
			$this->setWaiterDirect(null, $kind);
			$waiter->resume();
		}
	}

	/** Resumes every waiting fiber: the stream is closing, and each wait re-checks its condition. */
	private function wakeAllWaiters(): void
	{
		foreach ([self::WAIT_READ, self::WAIT_WRITE, self::WAIT_RESPONSE] as $kind) {
			$this->wakeWaiter($kind);
		}
	}

	// =========================================================================
	// StreamInterface
	// =========================================================================

	/**
	 * Queues bytes for outgoing DATA frames and resumes the stream so nghttp2 sends them.
	 * @param string $string The bytes to send.
	 * @throws \RuntimeException When the stream is not writable (closed, detached, or local-closed).
	 * @return int The number of bytes queued.
	 */
	public function write(string $string): int
	{
		if ($this->getDetachedDirect()) {
			throw new \RuntimeException('Cannot write to a detached or closed HTTP/2 stream.');
		}
		if ($this->getLocalClosedDirect()) {
			throw new \RuntimeException('Cannot write to a non-writable HTTP/2 stream; the local side is closed.');
		}
		$outgoing = &$this->getOutgoingDirect();
		$outgoing .= $string;
		$this->getSessionDirect()->resumeStream($this->getStreamIdDirect());
		return strlen($string);
	}

	/**
	 * Returns up to $length buffered incoming bytes (non-blocking).  An open stream with nothing
	 * buffered returns '' rather than blocking.
	 * @param int $length The maximum number of bytes to return.
	 * @throws \RuntimeException When the stream is detached/closed, or $length is negative.
	 * @return string The bytes read, or '' when none are buffered.
	 */
	public function read(int $length): string
	{
		if ($this->getDetachedDirect()) {
			throw new \RuntimeException('Cannot read from a detached or closed HTTP/2 stream.');
		}
		if ($length < 0) {
			throw new \RuntimeException('Length parameter cannot be negative.');
		}
		if ($length === 0 || !$this->hasIncoming()) {
			return '';
		}
		$incoming = &$this->getIncomingDirect();
		$offset = $this->getIncomingOffsetDirect();
		$bytes = substr($incoming, $offset, $length);
		$this->setIncomingOffsetDirect(self::advance($incoming, $offset + strlen($bytes)));
		$this->setReadPositionDirect($this->getReadPositionDirect() + strlen($bytes));
		return $bytes;
	}

	/**
	 * Returns and clears all buffered incoming bytes.
	 * @throws \RuntimeException When the stream is detached or closed.
	 * @return string The buffered incoming bytes.
	 */
	public function getContents(): string
	{
		if ($this->getDetachedDirect()) {
			throw new \RuntimeException('Cannot read from a detached or closed HTTP/2 stream.');
		}
		$bytes = substr($this->getIncomingDirect(), $this->getIncomingOffsetDirect());
		$this->setIncomingDirect('');
		$this->setReadPositionDirect($this->getReadPositionDirect() + strlen($bytes));
		return $bytes;
	}

	/**
	 * Closes the stream for both directions, clears its buffers, and ends it on the wire.  A peer
	 * that has not finished sending is cancelled (RST_STREAM CANCEL), so it cannot mistake the
	 * discarded body for a complete one; a peer that has finished sees this side end (END_STREAM).
	 * The stream becomes unusable: a later {@see read()}, {@see getContents()}, or {@see write()}
	 * throws (PSR-7).  Idempotent, and a no-op on the wire once the session is closed.
	 */
	public function close(): void
	{
		if ($this->getDetachedDirect()) {
			return;
		}
		$remoteOpen = !$this->getRemoteClosedDirect();
		$localOpen = !$this->getLocalClosedDirect();
		$this->setLocalClosedDirect(true);
		$this->setRemoteClosedDirect(true);
		$this->setDetachedDirect(true);
		$this->setIncomingDirect('');
		$this->setOutgoingDirect('');
		$this->setTrailersPendingDirect(false);
		$this->setTrailersDirect([]);
		$this->wakeAllWaiters();
		$session = $this->getSessionDirect();
		if ($remoteOpen) {
			try {
				$session->resetStream($this->getStreamIdDirect());
			} catch (THttp2Exception $e) {
				// The session is closed; nghttp2 no longer has the stream.
			}
		} elseif ($localOpen) {
			// The provider now finds nothing queued and the local side closed: it emits END_STREAM.
			$session->resumeStream($this->getStreamIdDirect());
		}
	}

	/**
	 * Detaches the stream (no underlying resource to return).
	 * @return null Always null; an HTTP/2 stream has no PHP resource.
	 */
	public function detach()
	{
		$this->close();
		return null;
	}

	/** @return bool Whether the peer has half-closed and the buffer is drained. */
	public function eof(): bool
	{
		return $this->getRemoteClosedDirect() && !$this->hasIncoming();
	}

	/** @return ?int Always null; an HTTP/2 stream length is unknown. */
	public function getSize(): ?int
	{
		return null;
	}

	/** @return int The total number of bytes read so far. */
	public function tell(): int
	{
		return $this->getReadPositionDirect();
	}

	/** @return bool Whether incoming bytes can be read (true until detached and drained). */
	public function isReadable(): bool
	{
		return !$this->getDetachedDirect()
			&& (!$this->getRemoteClosedDirect() || $this->hasIncoming());
	}

	/** @return bool Whether bytes can be written (true until this side closes or detaches). */
	public function isWritable(): bool
	{
		return !$this->getDetachedDirect() && !$this->getLocalClosedDirect();
	}

	/** @return bool Always false; an HTTP/2 stream is not seekable. */
	public function isSeekable(): bool
	{
		return false;
	}

	/**
	 * Throws: an HTTP/2 stream cannot seek.
	 * @param int $offset The seek offset (unused).
	 * @param int $whence The seek origin (unused).
	 * @throws \RuntimeException Always.
	 */
	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		throw new \RuntimeException('An HTTP/2 stream is not seekable.');
	}

	/**
	 * Throws: an HTTP/2 stream cannot seek.
	 * @throws \RuntimeException Always.
	 */
	public function rewind(): void
	{
		throw new \RuntimeException('An HTTP/2 stream is not seekable.');
	}

	/**
	 * Returns stream metadata (none for an HTTP/2 stream).
	 * @param ?string $key The metadata key, or null for all.
	 * @return null|array<string, mixed> An empty array for all, or null for a key.
	 */
	public function getMetadata(?string $key = null)
	{
		return $key === null ? [] : null;
	}

	/**
	 * Returns and clears the buffered incoming bytes.  Casting consumes the buffer (an HTTP/2
	 * stream is not seekable, so the bytes cannot be re-read).  Per PSR-7 this never throws: a
	 * detached or closed stream casts to ''.
	 * @return string The buffered incoming bytes.
	 */
	public function __toString(): string
	{
		try {
			return $this->getContents();
		} catch (\Throwable $e) {
			return '';
		}
	}
}
