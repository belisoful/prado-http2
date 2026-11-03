<?php

use Prado\IO\Http2\THttp2Exception;

/**
 * Unit-tests the `http2_*` error messages of config/errorMessages.txt (no libnghttp2 needed).
 *
 * PRADO 4.4 formats a message defined in a message file as an ICU MessageFormat pattern, so a
 * message whose quoting or braces ICU reads differently from plain `{0}` substitution would change
 * its text.  Each message must parse as ICU and format its arguments as plain substitution does.
 */
class THttp2ExceptionTest extends PHPUnit\Framework\TestCase
{
	public function testEveryMessageIsAnIcuPatternThatFormatsLikePlainSubstitution()
	{
		if (!class_exists(\MessageFormatter::class)) {
			$this->markTestSkipped('The intl extension (MessageFormatter) is not available.');
		}
		$messages = $this->readMessages();
		self::assertNotEmpty($messages, 'The message file defines error codes.');
		foreach ($messages as $code => $pattern) {
			self::assertStringStartsWith('http2_', $code);
			$formatter = \MessageFormatter::create('en', $pattern);
			self::assertNotNull($formatter, "$code is not a valid ICU MessageFormat pattern: " . intl_get_error_message());
			preg_match_all('/\{(\d+)\}/', $pattern, $matches);
			$args = [];
			$tokens = [];
			for ($i = 0, $n = $matches[1] === [] ? 0 : max(array_map('intval', $matches[1])) + 1; $i < $n; ++$i) {
				$args[$i] = "arg$i";
				$tokens['{' . $i . '}'] = "arg$i";
			}
			self::assertSame(strtr($pattern, $tokens), $formatter->format($args), "ICU formats $code differently from plain substitution; double an apostrophe next to a brace.");
		}
	}

	public function testExceptionFormatsItsMessageFromTheFile()
	{
		$e = new THttp2Exception('http2_library_too_old', '1.59.0', '/usr/lib/libnghttp2.so.14', '1.60.0');
		self::assertSame('http2_library_too_old', $e->getErrorCode());
		self::assertSame('libnghttp2 1.59.0 at /usr/lib/libnghttp2.so.14 is too old for HTTP/2 support; version 1.60.0 or newer is required.', $e->getMessage());

		$e = new THttp2Exception('http2_session_closed');
		self::assertSame('The HTTP/2 session is closed; nghttp2 calls are no longer possible.', $e->getMessage());
	}

	public function testArgumentWithIcuSyntaxIsInsertedLiterally()
	{
		$e = new THttp2Exception('http2_header_duplicate', "x-{0}-'quoted'");
		self::assertSame('The header field "x-{0}-\'quoted\'" is given more than once; HTTP/2 field names are case-insensitive.', $e->getMessage());
	}

	/**
	 * Reads the extension's message file the way PRADO does: `code = message` lines, with blank
	 * lines and `#` or `;` comments skipped.
	 * @return array<string, string> The messages by error code.
	 */
	private function readMessages(): array
	{
		$messages = [];
		foreach (file(dirname(__DIR__, 4) . '/config/errorMessages.txt', FILE_IGNORE_NEW_LINES) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || $line[0] === ';') {
				continue;
			}
			if (preg_match('/^(\w+)\s*=\s*(.*)$/', $line, $match)) {
				$messages[$match[1]] = $match[2];
			}
		}
		return $messages;
	}
}
