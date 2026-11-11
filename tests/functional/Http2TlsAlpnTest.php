<?php

use Prado\Exceptions\TCancelledException;
use Prado\IO\Async\TAsync;
use Prado\IO\Async\TAsyncStream;
use Prado\IO\Async\TCancellationToken;
use Prado\IO\Http2\TH2Alpn;
use Prado\IO\Http2\TH2Session;
use Prado\IO\Http2\THttp2Exception;
use Prado\IO\Http2\TNgHttp2;
use Prado\IO\Socket\TSocketStream;
use Prado\Prado;

/**
 * Proves {@see TH2Alpn} negotiates the `h2` ALPN protocol over a real PHP TLS handshake.  Two
 * in-process stream sockets complete a TLS handshake with a generated self-signed certificate,
 * each advertising `h2`; both ends then report `h2` as the negotiated protocol.  A second test
 * serves the system `curl --http2` over TLS through {@see TH2Session::pump()}.  Skipped when
 * `ext-openssl` (or in-memory certificate generation) is unavailable; a handshake that fails once
 * the prerequisites are met is a failure, not a skip.
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
		[$client, $conn, $listen] = $this->handshake([TH2Alpn::PROTOCOL_H2], [TH2Alpn::PROTOCOL_H2]);
		try {
			self::assertSame('h2', TH2Alpn::negotiatedProtocol($client), 'The client negotiated h2.');
			self::assertSame('h2', TH2Alpn::negotiatedProtocol($conn), 'The server negotiated h2.');
			self::assertTrue(TH2Alpn::negotiatedH2($client));
			self::assertTrue(TH2Alpn::negotiatedH2($conn));
			TH2Alpn::requireH2($conn);                         // h2 was negotiated: no exception
		} finally {
			fclose($client);
			fclose($conn);
			fclose($listen);
		}
	}

	public function testANegotiatedProtocolOtherThanH2IsNamedByRequireH2()
	{
		// The server offers h2 then http/1.1; the client offers only http/1.1.
		[$client, $conn, $listen] = $this->handshake([TH2Alpn::PROTOCOL_H2, TH2Alpn::PROTOCOL_HTTP11], [TH2Alpn::PROTOCOL_HTTP11]);
		try {
			self::assertSame('http/1.1', TH2Alpn::negotiatedProtocol($conn));
			self::assertSame('http/1.1', TH2Alpn::negotiatedProtocol($client));
			self::assertFalse(TH2Alpn::negotiatedH2($conn));
			try {
				TH2Alpn::requireH2($conn);
				self::fail('requireH2() accepted http/1.1.');
			} catch (THttp2Exception $e) {
				self::assertSame('http2_alpn_not_negotiated', $e->getErrorCode());
				self::assertStringContainsString('http/1.1', $e->getMessage());
			}
		} finally {
			fclose($client);
			fclose($conn);
			fclose($listen);
		}
	}

	/**
	 * Completes a TLS handshake between two in-process non-blocking sockets.  A missing prerequisite
	 * (certificate generation, a local socket) skips the test; a handshake that fails is a failure.
	 * @param string[] $serverProtocols The ALPN protocols the server offers, in preference order.
	 * @param string[] $clientProtocols The ALPN protocols the client offers.
	 * @return array{resource, resource, resource} The client stream, the accepted stream, and the listener.
	 */
	private function handshake(array $serverProtocols, array $clientProtocols): array
	{
		$this->certFile = $this->makeSelfSignedCert();
		if ($this->certFile === null) {
			$this->markTestSkipped('Could not generate a self-signed certificate.');
		}

		$serverCtx = stream_context_create(['ssl' => TH2Alpn::sslOptions([
			'local_cert' => $this->certFile,
			'verify_peer' => false,
			'allow_self_signed' => true,
		], $serverProtocols)]);
		$listen = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $serverCtx);
		if ($listen === false) {
			$this->markTestSkipped("Could not bind a local socket: $errstr");
		}
		$addr = stream_socket_get_name($listen, false);

		$clientCtx = stream_context_create(['ssl' => TH2Alpn::sslOptions([
			'verify_peer' => false,
			'verify_peer_name' => false,
			'allow_self_signed' => true,
		], $clientProtocols)]);
		$client = @stream_socket_client("tcp://$addr", $errno, $errstr, 5, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT, $clientCtx);
		$conn = false;
		try {
			self::assertNotFalse($client, "Client connect failed: $errstr");

			stream_set_blocking($listen, false);
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
					self::assertNotFalse($r, 'The TLS client handshake failed: ' . (openssl_error_string() ?: 'no OpenSSL error'));
					$clientDone = $r === true;
				}
				if (!$serverDone) {
					$r = @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
					self::assertNotFalse($r, 'The TLS server handshake failed: ' . (openssl_error_string() ?: 'no OpenSSL error'));
					$serverDone = $r === true;
				}
				usleep(1000);
			}
			self::assertTrue($clientDone && $serverDone, 'The TLS handshake completed within five seconds.');
		} catch (\Throwable $e) {
			// A failed assertion leaves no socket behind.
			foreach ([$client, $conn, $listen] as $resource) {
				if (is_resource($resource)) {
					fclose($resource);
				}
			}
			throw $e;
		}
		return [$client, $conn, $listen];
	}

	public function testCurlHttp2OverTlsThroughThePump()
	{
		if (!TNgHttp2::isAvailable()) {
			$this->markTestSkipped('libnghttp2 is not available.');
		}
		if (!class_exists(TAsync::class)) {
			$this->markTestSkipped('The PRADO async API (Prado\\IO\\Async\\TAsync) is not available.');
		}
		if (!function_exists('proc_open') || stripos((string) @shell_exec('curl --version 2>/dev/null'), 'HTTP2') === false) {
			$this->markTestSkipped('curl with HTTP/2 support is not available.');
		}
		$this->certFile = $this->makeSelfSignedCert();
		if ($this->certFile === null) {
			$this->markTestSkipped('Could not generate a self-signed certificate.');
		}
		$ctx = stream_context_create(['ssl' => TH2Alpn::sslOptions(['local_cert' => $this->certFile])]);
		$listen = @stream_socket_server('tls://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
		if ($listen === false) {
			$this->markTestSkipped("Could not bind a local socket: $errstr");
		}
		$name = (string) stream_socket_get_name($listen, false);
		$port = (int) substr($name, strrpos($name, ':') + 1);
		$body = str_repeat('0123456789', 20000);               // 200 KB each way: many TLS records and several windows
		$files = [tempnam(sys_get_temp_dir(), 'h2tls'), tempnam(sys_get_temp_dir(), 'h2tls')];
		file_put_contents($files[1], $body);
		$command = sprintf('curl -s -k --http2 --data-binary @%s https://127.0.0.1:%d/echo', escapeshellarg($files[1]), $port);
		$process = proc_open($command, [1 => ['file', $files[0], 'w'], 2 => ['pipe', 'w']], $pipes);
		$session = null;
		$transport = null;
		try {
			self::assertIsResource($process, 'Unable to launch curl.');
			$conn = @stream_socket_accept($listen, 10.0);          // the TLS handshake completes here
			self::assertIsResource($conn, 'curl did not complete a TLS connection: ' . (openssl_error_string() ?: 'no OpenSSL error'));
			TH2Alpn::requireH2($conn);

			$session = new TH2Session(true);
			$session->submitSettings([]);
			$session->attachEventHandler('onRequest', function ($s, $stream) use ($session) {
				TAsync::run(function () use ($session, $stream) {
					$received = '';
					while (($bytes = $stream->readAsync(65536)) !== '') {
						$received .= $bytes;
					}
					$session->respond($stream, [':status' => '200', 'content-type' => 'text/plain']);
					$stream->writeAsync(strrev($received));
					$stream->markLocalClosed();
				});
			});
			$transport = new TAsyncStream(Prado::createComponent(TSocketStream::class, $conn));
			try {
				$session->pump($transport, new TCancellationToken(15.0));   // runs the loop until curl hangs up
			} catch (TCancelledException $e) {
				self::fail('The pump did not finish before the deadline.');
			}
			$stderr = stream_get_contents($pipes[2]);
			fclose($pipes[2]);
			$exit = proc_close($process);
			$process = null;
			self::assertSame(0, $exit, "curl failed: $stderr");
			self::assertSame(strrev($body), (string) file_get_contents($files[0]), 'curl received the body over TLS.');
		} finally {
			if (is_resource($process)) {
				proc_terminate($process);
				proc_close($process);
			}
			foreach ($files as $file) {
				@unlink($file);
			}
			$session?->close();
			$transport?->close();
			fclose($listen);
		}
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
