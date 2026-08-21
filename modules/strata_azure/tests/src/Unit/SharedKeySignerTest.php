<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_azure\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\strata_azure\AzureCredentials;
use Drupal\strata_azure\SharedKeySigner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves the SharedKey scheme is implemented as Azure documents it.
 *
 * A signature is a pure function of its inputs, so this lane compares against the worked example
 * Microsoft publishes rather than against a live service. Every one of these assertions is a silent
 * 403 when it is wrong, and a 403 says nothing about which of the eleven positional lines moved.
 */
#[CoversClass(SharedKeySigner::class)]
#[CoversClass(AzureCredentials::class)]
class SharedKeySignerTest extends TestCase
{
	#region Fixtures

	/**
	 * Account the documented worked example is written for.
	 */
	private const ACCOUNT = 'myaccount';

	/**
	 * A key that is valid base64, which is all a signature needs of one.
	 */
	private const KEY = 'c3RyYXRhLXNoYXJlZC1rZXktZm9yLXRlc3Rpbmc=';

	/**
	 * The instant the documented worked example is signed at.
	 */
	private const STAMP = 'Fri, 26 Jun 2015 23:39:12 GMT';

	/**
	 * The signer under test.
	 *
	 * @return SharedKeySigner
	 *   A signer.
	 */
	private function signer(): SharedKeySigner
	{
		return new SharedKeySigner();
	}

	/**
	 * Credentials carrying an account key.
	 *
	 * @return AzureCredentials
	 *   The credentials.
	 */
	private function credentials(): AzureCredentials
	{
		return new AzureCredentials(self::ACCOUNT, self::KEY);
	}

	#endregion

	#region Documented vector

	#[Test]
	#[TestDox('the string to sign for a container listing matches the documented worked example')]
	#[Group('strata/azure')]
	public function matchesTheDocumentedListingVector(): void
	{
		$expected =
			"GET\n\n\n\n\n\n\n\n\n\n\n\n" .
			"x-ms-date:Fri, 26 Jun 2015 23:39:12 GMT\n" .
			"x-ms-version:2015-02-21\n" .
			"/myaccount/mycontainer\ncomp:list\nrestype:container\ntimeout:20";

		$this->assertSame(
			$expected,
			$this->signer()->stringToSign(
				self::ACCOUNT,
				'GET',
				'https://myaccount.blob.core.windows.net/mycontainer?restype=container&comp=list&timeout=20',
				['x-ms-date' => self::STAMP, 'x-ms-version' => '2015-02-21'],
			),
		);
	}

	#[Test]
	#[TestDox('every one of the eleven fixed header lines keeps its position')]
	#[Group('strata/azure')]
	public function fixedHeaderLinesArePositional(): void
	{
		$stringToSign = $this->signer()->stringToSign(
			self::ACCOUNT,
			'PUT',
			'https://myaccount.blob.core.windows.net/c/frames/one',
			[
				'Content-Length' => '4096',
				'Content-Type' => 'application/octet-stream',
				'Content-MD5' => 'Q2hlY2tzdW0=',
				'If-None-Match' => '*',
				'x-ms-blob-type' => 'BlockBlob',
				'x-ms-date' => self::STAMP,
			],
		);
		$lines = explode("\n", $stringToSign);

		$this->assertSame('PUT', $lines[0]);
		$this->assertSame('', $lines[1], 'content-encoding');
		$this->assertSame('', $lines[2], 'content-language');
		$this->assertSame('4096', $lines[3], 'content-length');
		$this->assertSame('Q2hlY2tzdW0=', $lines[4], 'content-md5');
		$this->assertSame('application/octet-stream', $lines[5], 'content-type');
		$this->assertSame('', $lines[6], 'date');
		$this->assertSame('', $lines[7], 'if-modified-since');
		$this->assertSame('', $lines[8], 'if-match');
		$this->assertSame('*', $lines[9], 'if-none-match');
		$this->assertSame('', $lines[10], 'if-unmodified-since');
		$this->assertSame('', $lines[11], 'range');
		$this->assertSame('x-ms-blob-type:BlockBlob', $lines[12]);
	}

	#[Test]
	#[TestDox('a zero content length signs as an empty line rather than as a zero')]
	#[Group('strata/azure')]
	public function zeroContentLengthSignsAsEmpty(): void
	{
		$lines = explode(
			"\n",
			$this->signer()->stringToSign(
				self::ACCOUNT,
				'PUT',
				'https://myaccount.blob.core.windows.net/c/empty',
				['Content-Length' => '0', 'x-ms-date' => self::STAMP],
			),
		);

		$this->assertSame('', $lines[3]);
	}

	#endregion

	#region Canonicalisation

	#[Test]
	#[TestDox('canonicalized headers are lowercased, sorted and have their whitespace collapsed')]
	#[Group('strata/azure')]
	public function canonicalizedHeadersAreNormalised(): void
	{
		$this->assertSame(
			"x-ms-blob-type:BlockBlob\nx-ms-date:" .
				self::STAMP .
				"\nx-ms-meta-note:two words\nx-ms-version:2021-12-02\n",
			$this->signer()->canonicalizedHeaders([
				'X-Ms-Version' => '2021-12-02',
				'x-ms-meta-note' => "  two    words \n",
				'X-MS-Date' => self::STAMP,
				'x-ms-blob-type' => 'BlockBlob',
				'Content-Type' => 'text/plain',
			]),
		);
	}

	#[Test]
	#[TestDox('a request with no x-ms headers canonicalizes to nothing at all')]
	#[Group('strata/azure')]
	public function noCanonicalHeadersIsEmpty(): void
	{
		$this->assertSame(
			'',
			$this->signer()->canonicalizedHeaders(['Content-Type' => 'text/plain']),
		);
	}

	#[Test]
	#[TestDox('query parameters are decoded, lowercased and sorted by name')]
	#[Group('strata/azure')]
	public function queryParametersAreCanonicalised(): void
	{
		$this->assertSame(
			"/myaccount/c\ncomp:list\nprefix:_strata/site-1/\nrestype:container",
			$this->signer()->canonicalizedResource(
				self::ACCOUNT,
				'https://myaccount.blob.core.windows.net/c?restype=container&COMP=list&prefix=_strata%2Fsite-1%2F',
			),
		);
	}

	#[Test]
	#[TestDox('a repeated query parameter has its values sorted and joined with a comma')]
	#[Group('strata/azure')]
	public function repeatedQueryParametersAreJoined(): void
	{
		$this->assertSame(
			"/myaccount/c\ninclude:metadata,snapshots",
			$this->signer()->canonicalizedResource(
				self::ACCOUNT,
				'https://myaccount.blob.core.windows.net/c?include=snapshots&include=metadata',
			),
		);
	}

	#[Test]
	#[TestDox('an emulator path names the account twice, which is what azurite computes')]
	#[Group('strata/azure')]
	public function emulatorPathRepeatsTheAccount(): void
	{
		$this->assertSame(
			'/devstoreaccount1/devstoreaccount1/strata/frames/one',
			$this->signer()->canonicalizedResource(
				'devstoreaccount1',
				'http://127.0.0.1:10000/devstoreaccount1/strata/frames/one',
			),
		);
	}

	#endregion

	#region Signing

	#[Test]
	#[TestDox('the signature is base64 hmac-sha256 over the decoded account key')]
	#[Group('strata/azure')]
	public function signatureIsHmacOverTheDecodedKey(): void
	{
		$stringToSign = "GET\n\n\n\n\n\n\n\n\n\n\n\nx-ms-date:" . self::STAMP . "\n/myaccount/c";

		$this->assertSame(
			base64_encode(
				hash_hmac('sha256', $stringToSign, (string) base64_decode(self::KEY, true), true),
			),
			$this->signer()->signature($this->credentials()->signingKey(), $stringToSign),
		);
	}

	#[Test]
	#[TestDox('signing adds a date and an authorization naming the account')]
	#[Group('strata/azure')]
	public function signingAddsTheAuthorizationHeader(): void
	{
		$signed = $this->signer()->sign(
			$this->credentials(),
			'GET',
			'https://myaccount.blob.core.windows.net/c/frames/one',
			['x-ms-version' => '2021-12-02'],
			new DateTimeImmutable('2026-08-19 12:00:00', new DateTimeZone('UTC')),
		);

		$this->assertSame('Wed, 19 Aug 2026 12:00:00 GMT', $signed['x-ms-date']);
		$this->assertStringStartsWith('SharedKey myaccount:', $signed['Authorization']);
		$this->assertSame('2021-12-02', $signed['x-ms-version'], 'other headers travel unchanged');
	}

	#[Test]
	#[TestDox('a date already on the request is signed rather than replaced')]
	#[Group('strata/azure')]
	public function anExistingDateIsKept(): void
	{
		$signed = $this->signer()->sign(
			$this->credentials(),
			'GET',
			'https://myaccount.blob.core.windows.net/c/frames/one',
			['x-ms-date' => self::STAMP],
			new DateTimeImmutable('2026-08-19 12:00:00', new DateTimeZone('UTC')),
		);

		$this->assertSame(self::STAMP, $signed['x-ms-date']);
	}

	#[Test]
	#[TestDox('the same inputs at the same instant produce the same signature')]
	#[Group('strata/azure')]
	public function signingIsDeterministic(): void
	{
		$now = new DateTimeImmutable('2026-08-19 12:00:00', new DateTimeZone('UTC'));
		$sign = fn(): string => (string) $this->signer()->sign(
			$this->credentials(),
			'PUT',
			'https://myaccount.blob.core.windows.net/c/frames/one?comp=block&blockid=AAA',
			['Content-Length' => '3', 'x-ms-version' => '2021-12-02'],
			$now,
		)['Authorization'];

		$this->assertSame($sign(), $sign());
	}

	#[Test]
	#[TestDox('signing with credentials that carry only a sas token is refused')]
	#[Group('strata/azure')]
	public function signingWithoutAnAccountKeyIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('needs an account key');

		$this->signer()->sign(
			new AzureCredentials(self::ACCOUNT, '', 'sv=2021-12-02&sig=abc'),
			'GET',
			'https://myaccount.blob.core.windows.net/c',
			[],
			new DateTimeImmutable('now', new DateTimeZone('UTC')),
		);
	}

	#endregion

	#region Credentials

	#[Test]
	#[TestDox('a sas token keeps its value whether or not it was pasted with a question mark')]
	#[Group('strata/azure')]
	public function sasTokensAreNormalised(): void
	{
		$with = new AzureCredentials(self::ACCOUNT, '', '?sv=2021-12-02&sig=abc');
		$without = new AzureCredentials(self::ACCOUNT, '', 'sv=2021-12-02&sig=abc');

		$this->assertSame('sv=2021-12-02&sig=abc', $with->sasQuery);
		$this->assertSame($without->sasQuery, $with->sasQuery);
		$this->assertTrue($with->hasSas());
		$this->assertFalse($with->hasSharedKey());
		$this->assertSame('a sas token', $with->describe());
	}

	#[Test]
	#[TestDox('an account key that is not base64 is refused rather than signing with nothing')]
	#[Group('strata/azure')]
	public function nonBase64AccountKeyIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('has to be base64');

		new AzureCredentials(self::ACCOUNT, 'not base64 at all!!');
	}

	#[Test]
	#[TestDox('credentials with neither a key nor a token are refused')]
	#[Group('strata/azure')]
	public function credentialsWithNothingAreRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('either an account key or a sas token');

		new AzureCredentials(self::ACCOUNT);
	}

	#endregion
}
