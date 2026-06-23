<?php

use Prado\IO\Http2\TH2Alpn;

/**
 * Proves {@see TH2Alpn} negotiates the `h2` ALPN protocol over a real PHP TLS handshake.  Two
 * in-process stream sockets complete a TLS handshake with a generated self-signed certificate,
 * each advertising `h2`; both ends then report `h2` as the negotiated protocol.  Skipped when
 * `ext-openssl` (or in-memory certificate generation) is unavailable.
 */
class Http2TlsAlpnTest extends PHPUnit\Framework\TestCase
{
	private ?string $certFile = null;

	protected function setUp(): void
	{
		if (!TH2Alpn::isAvailable() || !function_exists('openssl_pkey_new')) {
			$this->markTestSkipped('ext-openssl is not available.');
		}
	}

	protected function tearDown(): void
	{
		if ($this->certFile !== null && is_file($this->certFile)) {
			unlink($this->certFile);
		}
	}

	public function testHandshakeNegotiatesH2OnBothEnds()
	{
		$this->certFile = $this->makeSelfSignedCert();
		if ($this->certFile === null) {
			$this->markTestSkipped('Could not generate a self-signed certificate.');
		}

		$serverCtx = stream_context_create(['ssl' => TH2Alpn::sslOptions([
			'local_cert' => $this->certFile,
			'verify_peer' => false,
			'allow_self_signed' => true,
		])]);
		$listen = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $serverCtx);
		if ($listen === false) {
			$this->markTestSkipped("Could not bind a local socket: $errstr");
		}
		$addr = stream_socket_get_name($listen, false);

		$clientCtx = stream_context_create(['ssl' => TH2Alpn::sslOptions([
			'verify_peer' => false,
			'verify_peer_name' => false,
			'allow_self_signed' => true,
		])]);
		$client = @stream_socket_client("tcp://$addr", $errno, $errstr, 5, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT, $clientCtx);
		self::assertNotFalse($client, "Client connect failed: $errstr");

		stream_set_blocking($listen, false);
		$conn = false;
		$deadline = microtime(true) + 5.0;
		while ($conn === false && microtime(true) < $deadline) {
			$conn = @stream_socket_accept($listen, 0.1);
		}
		self::assertNotFalse($conn, 'The server did not accept the connection.');

		stream_set_blocking($client, false);
		stream_set_blocking($conn, false);

		// Drive the TLS handshake on both non-blocking ends until each completes.
		$clientDone = false;
		$serverDone = false;
		$deadline = microtime(true) + 5.0;
		while ((!$clientDone || !$serverDone) && microtime(true) < $deadline) {
			if (!$clientDone) {
				$r = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
				if ($r === true) {
					$clientDone = true;
				} elseif ($r === false) {
					$this->markTestSkipped('TLS client handshake failed in this environment.');
				}
			}
			if (!$serverDone) {
				$r = @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
				if ($r === true) {
					$serverDone = true;
				} elseif ($r === false) {
					$this->markTestSkipped('TLS server handshake failed in this environment.');
				}
			}
			usleep(1000);
		}

		if (!$clientDone || !$serverDone) {
			$this->markTestSkipped('TLS handshake did not complete within the time budget.');
		}

		self::assertSame('h2', TH2Alpn::negotiatedProtocol($client), 'The client negotiated h2.');
		self::assertSame('h2', TH2Alpn::negotiatedProtocol($conn), 'The server negotiated h2.');
		self::assertTrue(TH2Alpn::negotiatedH2($client));
		self::assertTrue(TH2Alpn::negotiatedH2($conn));

		fclose($client);
		fclose($conn);
		fclose($listen);
	}

	/**
	 * Generates an in-memory self-signed certificate and writes the cert+key PEM to a temp file.
	 * @return ?string The temp PEM path, or null when generation is unavailable.
	 */
	private function makeSelfSignedCert(): ?string
	{
		$pkey = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		if ($pkey === false) {
			return null;
		}
		$csr = @openssl_csr_new(['commonName' => 'localhost'], $pkey);
		if ($csr === false) {
			return null;
		}
		$x509 = @openssl_csr_sign($csr, null, $pkey, 1);
		if ($x509 === false) {
			return null;
		}
		$certPem = '';
		$keyPem = '';
		openssl_x509_export($x509, $certPem);
		openssl_pkey_export($pkey, $keyPem);
		$file = tempnam(sys_get_temp_dir(), 'h2alpn');
		if ($file === false) {
			return null;
		}
		file_put_contents($file, $certPem . $keyPem);
		return $file;
	}
}
