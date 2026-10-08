<?php

/**
 * TH2Stream class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-http2
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Http2;

use Prado\TComponent;
use Psr\Http\Message\StreamInterface;

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

	// =========================================================================
	// Session Plumbing
	// =========================================================================

	/**
	 * Appends received DATA bytes to the incoming buffer (called by {@see TH2Session}).  A detached
	 * stream drops them: nothing can read them.
	 * @param string $bytes The received bytes.
	 */
	public function pushIncoming(string $bytes): void
	{
		if ($this->getDetachedDirect()) {
			return;
		}
		$incoming = &$this->getIncomingDirect();
		$incoming .= $bytes;
	}

	/**
	 * Removes and returns up to $length queued outgoing bytes (called by the data provider).
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

	/** Marks the peer as half-closed; no further incoming DATA arrives. */
	public function markRemoteClosed(): void
	{
		$this->setRemoteClosedDirect(true);
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
	 * as `123` survives (PHP stores it as an integer key).
	 * @param array<string, string> $headers The headers to merge.
	 */
	public function mergeHeaders(array $headers): void
	{
		$this->setHeadersDirect(array_replace($this->getHeadersDirect(), $headers));
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
