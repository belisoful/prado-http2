<?php

/**
 * TH2Options class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-http2
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Http2;

use Prado\TComponent;

/**
 * TH2Options class.
 *
 * Tuning for a {@see TH2Session}, applied at creation through nghttp2's option API.  Each value
 * is optional: a null limit leaves nghttp2's default in place.
 *
 *  - {@see setPeerMaxConcurrentStreams() PeerMaxConcurrentStreams}: the assumed peer limit before
 *    its SETTINGS arrive, bounding how many streams open eagerly.
 *  - {@see setNoAutoWindowUpdate() NoAutoWindowUpdate}: when true, the application drives flow
 *    control itself with {@see TH2Session::consume()} and {@see TH2Session::submitWindowUpdate()}.
 *
 * A session with no options uses nghttp2's defaults (automatic flow control).  The local maximum
 * concurrent streams is a SETTING, set with {@see TH2Session::submitSettings()} using
 * {@see TNgHttp2::SETTINGS_MAX_CONCURRENT_STREAMS}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TH2Options extends TComponent
{
	/** @var ?int The assumed peer concurrency limit before its SETTINGS arrive, or null. */
	private ?int $_peerMaxConcurrentStreams = null;

	/** @var bool Whether automatic flow-control window updates are disabled. */
	private bool $_noAutoWindowUpdate = false;

	// =========================================================================
	// Self-Encapsulated Accessors
	// =========================================================================

	/** @return ?int The raw peer concurrency limit, or null. */
	protected function getPeerMaxConcurrentStreamsDirect(): ?int
	{
		return $this->_peerMaxConcurrentStreams;
	}

	/** @param ?int $value The raw peer concurrency limit, or null. */
	protected function setPeerMaxConcurrentStreamsDirect(?int $value): void
	{
		$this->_peerMaxConcurrentStreams = $value;
	}

	/** @return bool The raw no-auto-window-update flag. */
	protected function getNoAutoWindowUpdateDirect(): bool
	{
		return $this->_noAutoWindowUpdate;
	}

	/** @param bool $value The raw no-auto-window-update flag. */
	protected function setNoAutoWindowUpdateDirect(bool $value): void
	{
		$this->_noAutoWindowUpdate = $value;
	}

	// =========================================================================
	// Properties
	// =========================================================================

	/** @return ?int The assumed peer concurrency limit, or null for nghttp2's default. */
	public function getPeerMaxConcurrentStreams(): ?int
	{
		return $this->getPeerMaxConcurrentStreamsDirect();
	}

	/** @param ?int $value The assumed peer concurrency limit, or null for nghttp2's default. */
	public function setPeerMaxConcurrentStreams(?int $value): void
	{
		$this->setPeerMaxConcurrentStreamsDirect($value);
	}

	/** @return bool Whether automatic flow-control window updates are disabled. */
	public function getNoAutoWindowUpdate(): bool
	{
		return $this->getNoAutoWindowUpdateDirect();
	}

	/** @param bool $value Whether to disable automatic flow-control window updates. */
	public function setNoAutoWindowUpdate(bool $value): void
	{
		$this->setNoAutoWindowUpdateDirect($value);
	}

	/**
	 * Indicates whether any option deviates from nghttp2's defaults (and so an option object is built).
	 * @return bool Whether at least one option is set.
	 */
	public function isEmpty(): bool
	{
		return $this->getPeerMaxConcurrentStreamsDirect() === null
			&& !$this->getNoAutoWindowUpdateDirect();
	}
}
