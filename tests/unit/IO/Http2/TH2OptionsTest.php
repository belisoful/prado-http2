<?php

use Prado\IO\Http2\TH2Options;

/**
 * Unit-tests the {@see TH2Options} config holder (no libnghttp2 needed).
 */
class TH2OptionsTest extends PHPUnit\Framework\TestCase
{
	public function testDefaultsAreEmpty()
	{
		$options = new TH2Options();
		self::assertNull($options->getPeerMaxConcurrentStreams());
		self::assertFalse($options->getNoAutoWindowUpdate());
		self::assertTrue($options->isEmpty(), 'A fresh options object is empty.');
	}

	public function testPeerMaxConcurrentStreams()
	{
		$options = new TH2Options();
		$options->setPeerMaxConcurrentStreams(100);
		self::assertSame(100, $options->getPeerMaxConcurrentStreams());
		self::assertFalse($options->isEmpty());
	}

	public function testNoAutoWindowUpdate()
	{
		$options = new TH2Options();
		$options->setNoAutoWindowUpdate(true);
		self::assertTrue($options->getNoAutoWindowUpdate());
		self::assertFalse($options->isEmpty());
	}

	public function testPropertyAccessThroughComponentMagic()
	{
		// Prado property system: getters/setters are reachable as properties.
		$options = new TH2Options();
		$options->PeerMaxConcurrentStreams = 7;
		self::assertSame(7, $options->PeerMaxConcurrentStreams);
	}
}
