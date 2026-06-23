<?php

use Prado\IO\Http2\TH2Alpn;
use Prado\IO\Http2\THttp2Exception;

/**
 * Unit-tests the {@see TH2Alpn} TLS/ALPN helper.  The context-building and negotiation-reading
 * logic is covered without a TLS handshake; the real `h2` negotiation is proven by the functional
 * suite.
 */
class TH2AlpnTest extends PHPUnit\Framework\TestCase
{
	public function testConstants()
	{
		self::assertSame('h2', TH2Alpn::PROTOCOL_H2);
		self::assertSame('http/1.1', TH2Alpn::PROTOCOL_HTTP11);
	}

	public function testIsAvailableMatchesOpenssl()
	{
		self::assertSame(extension_loaded('openssl'), TH2Alpn::isAvailable());
	}

	public function testSslOptionsAdvertisesH2ByDefault()
	{
		self::assertSame(['alpn_protocols' => 'h2'], TH2Alpn::sslOptions());
	}

	public function testSslOptionsMergesAndKeepsExistingOptions()
	{
		$ssl = TH2Alpn::sslOptions(['local_cert' => '/path/server.pem', 'verify_peer' => false]);
		self::assertSame('/path/server.pem', $ssl['local_cert']);
		self::assertFalse($ssl['verify_peer']);
		self::assertSame('h2', $ssl['alpn_protocols']);
	}

	public function testSslOptionsAcceptsAFallbackList()
	{
		$ssl = TH2Alpn::sslOptions([], [TH2Alpn::PROTOCOL_H2, TH2Alpn::PROTOCOL_HTTP11]);
		self::assertSame('h2,http/1.1', $ssl['alpn_protocols']);
	}

	public function testNegotiatedProtocolNullOnANonResource()
	{
		self::assertNull(TH2Alpn::negotiatedProtocol(null));
		self::assertNull(TH2Alpn::negotiatedProtocol('not a stream'));
	}

	public function testNegotiatedProtocolNullOnAPlaintextStream()
	{
		$stream = fopen('php://memory', 'r+');
		self::assertNull(TH2Alpn::negotiatedProtocol($stream), 'No ALPN on an unencrypted stream.');
		self::assertFalse(TH2Alpn::negotiatedH2($stream));
		fclose($stream);
	}

	public function testRequireH2ThrowsWhenH2WasNotNegotiated()
	{
		$stream = fopen('php://memory', 'r+');
		try {
			$this->expectException(THttp2Exception::class);
			TH2Alpn::requireH2($stream);
		} finally {
			fclose($stream);
		}
	}
}
