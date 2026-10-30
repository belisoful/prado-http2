<?php

use Prado\Exceptions\TCancelledException;
use Prado\IO\Async\TAsync;
use Prado\IO\Async\TAsyncStream;
use Prado\IO\Async\TCancellationToken;
use Prado\IO\Http2\TH2Session;
use Prado\IO\Http2\TNgHttp2;
use Prado\IO\Socket\TSocketStream;
use Prado\Prado;

/**
 * Interoperability tests: serve a cleartext HTTP/2 (h2c) request with a {@see TH2Session} server
 * over a real socket, and drive it with the system `curl --http2-prior-knowledge`.
 *
 * Unlike the in-process unit tests (two nghttp2 sessions over a memory pipe), these exercise the
 * real socket transport and an independent HTTP/2 client. They are skipped when libnghttp2, a
 * HTTP/2-capable curl, or proc_open is unavailable, so they never fail a constrained environment.
 */
class Http2InteropTest extends PHPUnit\Framework\TestCase
{
	protected function setUp(): void
	{
		if (!TNgHttp2::isAvailable()) {
			$this->markTestSkipped('libnghttp2 is not available.');
		}
		if (!function_exists('proc_open')) {
			$this->markTestSkipped('proc_open is disabled.');
		}
		if (stripos((string) @shell_exec('curl --version 2>/dev/null'), 'HTTP2') === false) {
			$this->markTestSkipped('curl with HTTP/2 support is not available.');
		}
	}

	public function testCurlH2cGetRequest()
	{
		$body = 'hello from prado-http2';
		$result = $this->exchange('', function (TH2Session $session) use ($body) {
			$session->attachEventHandler('onRequest', function ($s, $stream) use ($session, $body) {
				$session->respond($stream, [':status' => '200', 'content-type' => 'text/plain']);
				$stream->write($body);
				$stream->markLocalClosed();
			});
		});

		self::assertSame(0, $result['exit'], "curl failed: {$result['stderr']}");
		self::assertSame($body, $result['stdout'], 'curl received the HTTP/2 response body.');
	}

	public function testCurlH2cPostDeliversRequestBody()
	{
		$received = '';
		$result = $this->exchange("--data-binary 'ping-from-curl'", function (TH2Session $session) use (&$received) {
			$session->attachEventHandler('onRequest', function ($s, $stream) use ($session) {
				$session->respond($stream, [':status' => '200', 'content-type' => 'text/plain']);
				$stream->write('server-ack');
				$stream->markLocalClosed();
			});
			$session->attachEventHandler('onData', function ($s, $stream) use (&$received) {
				$received .= $stream->getContents();
			});
		});

		self::assertSame(0, $result['exit'], "curl failed: {$result['stderr']}");
		self::assertSame('server-ack', $result['stdout'], 'curl received the response.');
		self::assertSame('ping-from-curl', $received, 'The server read the HTTP/2 request body.');
	}

	public function testCurlH2cPostThroughThePumpAndAWorkerFiber()
	{
		if (!class_exists(TAsync::class)) {
			$this->markTestSkipped('The PRADO async API (Prado\\IO\\Async\\TAsync) is not available.');
		}
		$body = str_repeat('0123456789', 20000);               // 200 KB: several flow-control windows each way
		$result = $this->exchange('', function (TH2Session $session) {
			$session->attachEventHandler('onRequest', function ($s, $stream) use ($session) {
				// The handler returns at once; the fiber reads the body as it arrives, then answers.
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
		}, true, $body);

		self::assertSame(0, $result['exit'], "curl failed: {$result['stderr']}");
		self::assertSame(strrev($body), $result['stdout'], 'curl received the body the fiber wrote under backpressure.');
	}

	/**
	 * Serves a single h2c request through a {@see TH2Session} and returns curl's result.
	 * @param string $curlExtra Extra curl arguments (e.g. a POST body).
	 * @param callable $configure Wires the session's event handlers.
	 * @param bool $pump Whether to drive the session with {@see TH2Session::pump()} on the event
	 *   loop (from the main fiber) instead of the blocking read/write loop.
	 * @param string $requestBody A request body curl posts from a file (`--data-binary`).
	 * @return array{exit: int, stdout: string, stderr: string} curl's exit code and captured output.
	 */
	private function exchange(string $curlExtra, callable $configure, bool $pump = false, string $requestBody = ''): array
	{
		$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		self::assertIsResource($server, "Unable to bind server: $errstr");
		$name = (string) stream_socket_get_name($server, false);
		$port = (int) substr($name, strrpos($name, ':') + 1);

		// curl's output goes to a file: a pipe nobody drains while the session is served fills up
		// on a large body, and curl then blocks before it closes the connection.
		$files = [tempnam(sys_get_temp_dir(), 'h2curl')];
		if ($requestBody !== '') {
			$files[] = tempnam(sys_get_temp_dir(), 'h2curl');
			file_put_contents($files[1], $requestBody);
			$curlExtra .= ' --data-binary @' . escapeshellarg($files[1]);
		}
		$command = sprintf('curl -s %s --http2-prior-knowledge http://127.0.0.1:%d/x', $curlExtra, $port);
		$process = proc_open($command, [1 => ['file', $files[0], 'w'], 2 => ['pipe', 'w']], $pipes);
		self::assertIsResource($process, 'Unable to launch curl.');

		$connection = @stream_socket_accept($server, 5.0);
		self::assertIsResource($connection, 'curl did not connect.');

		$session = new TH2Session(true);
		$session->submitSettings([]);
		$configure($session);

		if ($pump) {
			$transport = new TAsyncStream(Prado::createComponent(TSocketStream::class, $connection));
			try {
				$session->pump($transport, new TCancellationToken(10.0));   // from the main fiber: runs the loop until curl hangs up
			} catch (TCancelledException $e) {
				self::fail('The pump did not finish before the deadline.');
			}
		} else {
			stream_set_timeout($connection, 2);
			$deadline = microtime(true) + 5.0;
			while (microtime(true) < $deadline) {
				$bytes = fread($connection, 65536);
				if ($bytes === '' || $bytes === false) {
					$meta = stream_get_meta_data($connection);
					if (!empty($meta['eof']) || !empty($meta['timed_out'])) {
						break;
					}
					continue;
				}
				$session->receive($bytes);
				$out = $session->send();
				if ($out !== '') {
					fwrite($connection, $out);
				}
			}
		}

		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$exit = proc_close($process);
		$stdout = (string) file_get_contents($files[0]);
		foreach ($files as $file) {
			unlink($file);
		}

		$session->close();
		if (isset($transport)) {
			$transport->close();                               // the socket stream owns the connection
		} else {
			fclose($connection);
		}
		fclose($server);

		return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
	}
}
