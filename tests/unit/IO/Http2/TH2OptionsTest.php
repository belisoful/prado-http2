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

	public function testConsumeOnRead()
	{
		$options = new TH2Options();
		self::assertFalse($options->getConsumeOnRead());
		$options->setConsumeOnRead(true);
		self::assertTrue($options->getConsumeOnRead());
		self::assertFalse($options->getNoAutoWindowUpdate(), 'The two flags are independent values.');
		self::assertFalse($options->isEmpty());
	}

	public function testMaxHeaderListSize()
	{
		$options = new TH2Options();
		self::assertNull($options->getMaxHeaderListSize(), 'Null leaves the session default in place.');
		$options->setMaxHeaderListSize(8192);
		self::assertSame(8192, $options->getMaxHeaderListSize());
		self::assertFalse($options->isEmpty());
		$options->setMaxHeaderListSize(0);
		self::assertSame(0, $options->getMaxHeaderListSize(), 'Zero turns the limit off.');
		self::assertFalse($options->isEmpty(), 'Zero is a set value, not the default.');
		$options->setMaxHeaderListSize(-5);
		self::assertSame(0, $options->getMaxHeaderListSize(), 'A negative value is treated as zero.');
		$options->setMaxHeaderListSize(null);
		self::assertTrue($options->isEmpty());
	}

	public function testPropertyAccessThroughComponentMagic()
	{
		// Prado property system: getters/setters are reachable as properties.
		$options = new TH2Options();
		$options->PeerMaxConcurrentStreams = 7;
		self::assertSame(7, $options->PeerMaxConcurrentStreams);
	}
}
