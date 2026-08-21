<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_s3\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\strata_s3\Credentials\Credentials;
use Drupal\strata_s3\SigV4Signer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SigV4Signer::class)]
#[CoversClass(Credentials::class)]
class SigV4SignerTest extends TestCase
{
	#region Fixtures

	/**
	 * Credentials AWS publishes its S3 worked examples under.
	 */
	private const S3_KEY_ID = 'AKIAIOSFODNN7EXAMPLE';

	/**
	 * Secret AWS publishes its S3 worked examples under.
	 */
	private const S3_SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

	/**
	 * Where the vendored vectors live.
	 *
	 * @return string
	 *   Absolute path to the fixture directory.
	 */
	private static function directory(): string
	{
		return dirname(__DIR__, 5) . '/tests/fixtures/sigv4';
	}

	/**
	 * Every vendored vector.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function vectorProvider(): array
	{
		$cases = [];

		foreach (glob(self::directory() . '/*.json') ?: [] as $path) {
			$vector = json_decode((string) file_get_contents($path), true);

			self::assertIsArray($vector, sprintf('%s is not a json object', $path));

			$cases[(string) $vector['name']] = [$vector];
		}

		self::assertNotSame([], $cases, 'no sigv4 vectors were found');
		ksort($cases);

		return $cases;
	}

	/**
	 * Turns a vector's timestamp into the instant it names.
	 *
	 * @param string $stamp
	 *   A timestamp such as `20150830T123600Z`.
	 *
	 * @return DateTimeImmutable
	 *   The instant, in UTC.
	 */
	private function instant(string $stamp): DateTimeImmutable
	{
		$parsed = DateTimeImmutable::createFromFormat(
			'!Ymd\THis\Z',
			$stamp,
			new DateTimeZone('UTC'),
		);

		$this->assertInstanceOf(DateTimeImmutable::class, $parsed);

		return $parsed;
	}

	/**
	 * A signer for one vector.
	 *
	 * @param array<string, mixed> $vector
	 *   The vector.
	 *
	 * @return SigV4Signer
	 *   A signer scoped to the vector's region and service.
	 */
	private function signerFor(array $vector): SigV4Signer
	{
		return new SigV4Signer((string) $vector['region'], (string) $vector['service']);
	}

	/**
	 * The credentials one vector signs with.
	 *
	 * @param array<string, mixed> $vector
	 *   The vector.
	 *
	 * @return Credentials
	 *   The credentials.
	 */
	private function credentialsFor(array $vector): Credentials
	{
		$token = $vector['sessionToken'] ?? null;

		return new Credentials(
			(string) $vector['accessKeyId'],
			(string) $vector['secretAccessKey'],
			$token === null ? null : (string) $token,
		);
	}

	#endregion

	#region Vectors

	#[Test]
	#[TestDox('vector $_dataName canonicalises exactly as the specification says')]
	#[Group('strata/s3')]
	#[DataProvider('vectorProvider')]
	public function canonicalisesEveryVector(array $vector): void
	{
		$signer = $this->signerFor($vector);

		$this->assertSame(
			$vector['signedHeaders'],
			$signer->signedHeaders($vector['headers'], (string) $vector['url']),
			$vector['description'],
		);
		$this->assertSame(
			$vector['canonicalRequest'],
			$signer->canonicalRequest(
				(string) $vector['method'],
				(string) $vector['url'],
				$vector['headers'],
				(string) $vector['payloadHash'],
			),
			$vector['description'],
		);
	}

	#[Test]
	#[TestDox('vector $_dataName produces the expected string to sign')]
	#[Group('strata/s3')]
	#[DataProvider('vectorProvider')]
	public function buildsTheStringToSignOfEveryVector(array $vector): void
	{
		$signer = $this->signerFor($vector);

		$this->assertSame(
			$vector['stringToSign'],
			$signer->stringToSign(
				(string) $vector['canonicalRequest'],
				$this->instant((string) $vector['timestamp']),
			),
		);
	}

	#[Test]
	#[TestDox('vector $_dataName produces the expected authorization header')]
	#[Group('strata/s3')]
	#[DataProvider('vectorProvider')]
	public function signsEveryVector(array $vector): void
	{
		$headers = $this->signerFor($vector)->signRequest(
			$this->credentialsFor($vector),
			(string) $vector['method'],
			(string) $vector['url'],
			$vector['headers'],
			$this->instant((string) $vector['timestamp']),
			(string) $vector['payloadHash'],
		);

		$this->assertSame($vector['authorization'], $headers['Authorization'] ?? null);
	}

	#[Test]
	#[TestDox('vector $_dataName agrees with itself, so no expectation came from the signer')]
	#[Group('strata/s3')]
	#[DataProvider('vectorProvider')]
	public function vectorsAgreeWithThemselves(array $vector): void
	{
		$lines = explode("\n", (string) $vector['stringToSign']);
		$scope = sprintf(
			'%s/%s/%s/aws4_request',
			substr((string) $vector['timestamp'], 0, 8),
			$vector['region'],
			$vector['service'],
		);

		$this->assertCount(4, $lines);
		$this->assertSame(SigV4Signer::ALGORITHM, $lines[0]);
		$this->assertSame($vector['timestamp'], $lines[1]);
		$this->assertSame($scope, $lines[2]);

		// the hash is arithmetic over the hand-written canonical request, not over any output
		$this->assertSame(hash('sha256', (string) $vector['canonicalRequest']), $lines[3]);
		$this->assertStringContainsString(
			sprintf('Credential=%s/%s, ', $vector['accessKeyId'], $scope),
			(string) $vector['authorization'],
		);
		$this->assertStringContainsString(
			sprintf('SignedHeaders=%s, ', $vector['signedHeaders']),
			(string) $vector['authorization'],
		);
	}

	#[Test]
	#[TestDox('the payload hash line of a vector matches the body it names')]
	#[Group('strata/s3')]
	#[DataProvider('vectorProvider')]
	public function vectorBodiesHashToTheirPayloadLine(array $vector): void
	{
		$body = $vector['body'] ?? null;

		if ($body === null) {
			$this->assertContains($vector['payloadHash'], [
				SigV4Signer::EMPTY_PAYLOAD,
				SigV4Signer::UNSIGNED_PAYLOAD,
			]);

			return;
		}

		$this->assertSame(hash('sha256', (string) $body), $vector['payloadHash']);
	}

	#endregion

	#region Published examples

	#[Test]
	#[TestDox('the signing key matches the worked example aws publishes for us-east-1 and iam')]
	#[Group('strata/s3')]
	public function derivesThePublishedSigningKey(): void
	{
		$signer = new SigV4Signer('us-east-1', 'iam');

		$this->assertSame(
			'c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9',
			$signer->signingKey(
				'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
				$this->instant('20150830T123600Z'),
			),
		);
	}

	#[Test]
	#[TestDox('the ranged get object example from the s3 documentation reproduces exactly')]
	#[Group('strata/s3')]
	public function signsThePublishedRangedGetExample(): void
	{
		$headers = (new SigV4Signer('us-east-1'))->signRequest(
			new Credentials(self::S3_KEY_ID, self::S3_SECRET),
			'GET',
			'https://examplebucket.s3.amazonaws.com/test.txt',
			[
				'Host' => 'examplebucket.s3.amazonaws.com',
				'Range' => 'bytes=0-9',
				'x-amz-content-sha256' => SigV4Signer::EMPTY_PAYLOAD,
				'x-amz-date' => '20130524T000000Z',
			],
			$this->instant('20130524T000000Z'),
		);

		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, ' .
				'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, ' .
				'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
			$headers['Authorization'],
		);
	}

	#[Test]
	#[TestDox('the put object example from the s3 documentation reproduces exactly')]
	#[Group('strata/s3')]
	public function signsThePublishedPutExample(): void
	{
		$body = 'Welcome to Amazon S3.';

		// the documentation states this hash, so a mismatch here is a broken fixture not a signature
		$this->assertSame(
			'44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072',
			hash('sha256', $body),
		);

		$headers = (new SigV4Signer('us-east-1'))->signRequest(
			new Credentials(self::S3_KEY_ID, self::S3_SECRET),
			'PUT',
			'https://examplebucket.s3.amazonaws.com/test%24file.text',
			[
				'Date' => 'Fri, 24 May 2013 00:00:00 GMT',
				'Host' => 'examplebucket.s3.amazonaws.com',
				'x-amz-content-sha256' => hash('sha256', $body),
				'x-amz-date' => '20130524T000000Z',
				'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
			],
			$this->instant('20130524T000000Z'),
			hash('sha256', $body),
		);

		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, ' .
				'SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class, ' .
				'Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
			$headers['Authorization'],
		);
	}

	#[Test]
	#[TestDox('the presigned get example from the s3 documentation reproduces exactly')]
	#[Group('strata/s3')]
	public function buildsThePublishedPresignedExample(): void
	{
		$url = (new SigV4Signer('us-east-1'))->presign(
			new Credentials(self::S3_KEY_ID, self::S3_SECRET),
			'GET',
			'https://examplebucket.s3.amazonaws.com/test.txt',
			86400,
			$this->instant('20130524T000000Z'),
		);

		$this->assertSame(
			'https://examplebucket.s3.amazonaws.com/test.txt?X-Amz-Algorithm=AWS4-HMAC-SHA256' .
				'&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request' .
				'&X-Amz-Date=20130524T000000Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host' .
				'&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
			$url,
		);
	}

	#endregion

	#region Presigning

	/**
	 * @return array<string, array{int}>
	 */
	public static function badLifetimeProvider(): array
	{
		return [
			'zero seconds' => [0],
			'a negative lifetime' => [-1],
			'more than seven days' => [SigV4Signer::MAX_EXPIRES + 1],
		];
	}

	#[Test]
	#[TestDox('presigning for $_dataName is refused rather than clamped')]
	#[Group('strata/s3')]
	#[DataProvider('badLifetimeProvider')]
	public function refusesBadPresignLifetimes(int $expires): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('presigned url lasts');

		(new SigV4Signer('us-east-1'))->presign(
			new Credentials('AKIDEXAMPLE', 'secret'),
			'GET',
			'https://examplebucket.s3.amazonaws.com/test.txt',
			$expires,
			$this->instant('20130524T000000Z'),
		);
	}

	#[Test]
	#[TestDox('a session token travels in the query string of a presigned url')]
	#[Group('strata/s3')]
	public function presignedUrlCarriesTheSessionToken(): void
	{
		$url = (new SigV4Signer('us-east-1'))->presign(
			new Credentials(self::S3_KEY_ID, self::S3_SECRET, 'SESSIONTOKENEXAMPLE'),
			'GET',
			'https://examplebucket.s3.amazonaws.com/test.txt',
			3600,
			$this->instant('20130524T000000Z'),
		);

		$this->assertStringContainsString('X-Amz-Security-Token=SESSIONTOKENEXAMPLE', $url);
		$this->assertStringContainsString('X-Amz-Signature=', $url);
	}

	#[Test]
	#[TestDox('a query string already on the url survives into the presigned form')]
	#[Group('strata/s3')]
	public function presignedUrlKeepsExistingParameters(): void
	{
		$url = (new SigV4Signer('us-east-1'))->presign(
			new Credentials(self::S3_KEY_ID, self::S3_SECRET),
			'GET',
			'https://examplebucket.s3.amazonaws.com/test.txt?response-content-type=text%2Fplain',
			3600,
			$this->instant('20130524T000000Z'),
		);

		$this->assertStringContainsString('response-content-type=text%2Fplain', $url);
		$this->assertStringStartsWith('https://examplebucket.s3.amazonaws.com/test.txt?', $url);
	}

	#endregion

	#region Refusals

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function emptyScopeProvider(): array
	{
		return [
			'no region' => ['   ', 's3'],
			'no service' => ['us-east-1', ' '],
		];
	}

	#[Test]
	#[TestDox('a signer with $_dataName is refused at construction')]
	#[Group('strata/s3')]
	#[DataProvider('emptyScopeProvider')]
	public function refusesEmptyScope(string $region, string $service): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('signer needs');

		new SigV4Signer($region, $service);
	}

	#[Test]
	#[TestDox('a url naming no host cannot be signed')]
	#[Group('strata/s3')]
	public function refusesUrlWithoutHost(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('names no host');

		(new SigV4Signer('us-east-1'))->canonicalRequest('GET', '/frames/thing', []);
	}

	#[Test]
	#[TestDox('a content hash header disagreeing with the signed payload is refused')]
	#[Group('strata/s3')]
	public function refusesDisagreeingPayloadHash(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('but the payload hash being signed is');

		(new SigV4Signer('us-east-1'))->signRequest(
			new Credentials('AKIDEXAMPLE', 'secret'),
			'PUT',
			'https://examplebucket.s3.amazonaws.com/thing',
			['x-amz-content-sha256' => SigV4Signer::EMPTY_PAYLOAD],
			$this->instant('20130524T000000Z'),
			hash('sha256', 'a different body'),
		);
	}

	#endregion

	#region Details

	#[Test]
	#[TestDox('a non-default port is part of the signed host')]
	#[Group('strata/s3')]
	public function signsThePortWhenItIsNotTheDefault(): void
	{
		$signer = new SigV4Signer('us-east-1');

		$this->assertStringContainsString(
			"\nhost:minio.internal:9000\n",
			$signer->canonicalRequest('GET', 'http://minio.internal:9000/bucket/thing', []),
		);
		$this->assertStringContainsString(
			"\nhost:minio.internal\n",
			$signer->canonicalRequest('GET', 'http://minio.internal:80/bucket/thing', []),
		);
	}

	#[Test]
	#[TestDox('a session token is signed as a header rather than sent beside the signature')]
	#[Group('strata/s3')]
	public function addsTheSessionTokenHeader(): void
	{
		$headers = (new SigV4Signer('us-east-1'))->signRequest(
			new Credentials('AKIDEXAMPLE', 'secret', 'SESSIONTOKENEXAMPLE'),
			'GET',
			'https://examplebucket.s3.amazonaws.com/thing',
			[],
			$this->instant('20130524T000000Z'),
		);

		$this->assertSame('SESSIONTOKENEXAMPLE', $headers[SigV4Signer::TOKEN_HEADER]);
		$this->assertStringContainsString(
			'SignedHeaders=host;x-amz-date;x-amz-security-token',
			(string) $headers['Authorization'],
		);
	}

	#[Test]
	#[TestDox('a header with no name is left out of the signature')]
	#[Group('strata/s3')]
	public function headerWithNoNameIsIgnored(): void
	{
		$signer = new SigV4Signer('us-east-1');

		$this->assertSame(
			'host;x-amz-date',
			$signer->signedHeaders(
				['  ' => 'orphan', 'x-amz-date' => '20130524T000000Z'],
				'https://examplebucket.s3.amazonaws.com/thing',
			),
		);
	}

	#[Test]
	#[TestDox('the request date is added when the caller did not supply one')]
	#[Group('strata/s3')]
	public function addsTheRequestDate(): void
	{
		$headers = (new SigV4Signer('us-east-1'))->signRequest(
			new Credentials('AKIDEXAMPLE', 'secret'),
			'GET',
			'https://examplebucket.s3.amazonaws.com/thing',
			[],
			$this->instant('20130524T000000Z'),
		);

		$this->assertSame('20130524T000000Z', $headers[SigV4Signer::DATE_HEADER]);
	}

	#[Test]
	#[TestDox('the same instant in another zone produces the same signature')]
	#[Group('strata/s3')]
	public function ignoresTheZoneTheTimeArrivesIn(): void
	{
		$signer = new SigV4Signer('us-east-1');
		$credentials = new Credentials(self::S3_KEY_ID, self::S3_SECRET);
		$url = 'https://examplebucket.s3.amazonaws.com/test.txt';
		$utc = $this->instant('20130524T000000Z');
		$elsewhere = $utc->setTimezone(new DateTimeZone('America/New_York'));

		$this->assertSame(
			$signer->signRequest($credentials, 'GET', $url, [], $utc)['Authorization'],
			$signer->signRequest($credentials, 'GET', $url, [], $elsewhere)['Authorization'],
		);
	}

	#[Test]
	#[TestDox('the scope names the day, region, service and terminator in that order')]
	#[Group('strata/s3')]
	public function scopesSignaturesToTheDay(): void
	{
		$signer = new SigV4Signer('auto', 's3');

		$this->assertSame(
			'20150830/auto/s3/aws4_request',
			$signer->credentialScope($this->instant('20150830T123600Z')),
		);
		$this->assertSame('auto', $signer->region());
		$this->assertSame('s3', $signer->service());
	}

	#endregion
}
