<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_gcs\Unit;

use Drupal\strata\Storage\HttpTransport;
use Drupal\strata_gcs\Credentials\ServiceAccountCredentials;
use Drupal\strata_gcs\Credentials\StaticAccessToken;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Proves the service account flow produces an assertion Google would accept.
 *
 * Google's acceptance cannot be tested offline, so the next best thing is tested instead: the
 * assertion is verified with openssl against a key pair this test generated, which is exactly the
 * check the token endpoint performs. A JWT that verifies here and is rejected there is a claim
 * problem, and the claims are asserted field by field for that reason.
 */
#[CoversClass(ServiceAccountCredentials::class)]
#[CoversClass(StaticAccessToken::class)]
class ServiceAccountCredentialsTest extends TestCase
{
	#region Fixtures

	/**
	 * The account every fixture is issued by.
	 */
	private const EMAIL = 'strata@example-project.iam.gserviceaccount.com';

	/**
	 * Responses the handler hands out in order.
	 */
	private MockHandler $handler;

	/**
	 * Requests the client sent.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $history = [];

	/**
	 * The private key assertions are signed with, PEM encoded.
	 */
	private string $privateKey = '';

	/**
	 * The matching public key, PEM encoded.
	 */
	private string $publicKey = '';

	/**
	 * What the injected clock answers.
	 */
	private int $now = 1_760_000_000;

	protected function setUp(): void
	{
		parent::setUp();

		$this->handler = new MockHandler();
		$this->history = [];
		$this->now = 1_760_000_000;

		$key = openssl_pkey_new([
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		]);

		$this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key, 'this host cannot make a key');

		$private = '';
		openssl_pkey_export($key, $private);
		$details = openssl_pkey_get_details($key);

		$this->assertIsArray($details);

		$this->privateKey = $private;
		$this->publicKey = (string) $details['key'];
	}

	/**
	 * Queues one response.
	 *
	 * @param int $status
	 *   HTTP status.
	 * @param string $body
	 *   Response body.
	 */
	private function queue(int $status, string $body = ''): void
	{
		$this->handler->append(new Response($status, [], $body));
	}

	/**
	 * The credentials under test.
	 *
	 * @return ServiceAccountCredentials
	 *   Credentials over the recording transport and the injected clock.
	 */
	private function credentials(): ServiceAccountCredentials
	{
		$stack = HandlerStack::create($this->handler);
		$stack->push(Middleware::history($this->history));

		return new ServiceAccountCredentials(
			self::EMAIL,
			$this->privateKey,
			new HttpTransport(new Client(['handler' => $stack])),
			ServiceAccountCredentials::DEFAULT_TOKEN_URI,
			ServiceAccountCredentials::SCOPE,
			fn(): int => $this->now,
		);
	}

	/**
	 * The requests the credentials made.
	 *
	 * @return list<RequestInterface>
	 *   The requests, in order.
	 */
	private function requests(): array
	{
		return array_values(
			array_map(static function (array $entry): RequestInterface {
				$request = $entry['request'];

				return $request instanceof RequestInterface
					? $request
					: new Request('GET', 'https://nothing.test');
			}, $this->history),
		);
	}

	/**
	 * Decodes one base64url segment of an assertion.
	 *
	 * @param string $segment
	 *   The segment.
	 *
	 * @return array<mixed>
	 *   The parsed json.
	 */
	private function decode(string $segment): array
	{
		$json = base64_decode(strtr($segment, '-_', '+/'), true);

		$this->assertIsString($json);

		$parsed = json_decode($json, true);

		$this->assertIsArray($parsed);

		return $parsed;
	}

	/**
	 * A token response.
	 *
	 * @param string $token
	 *   The access token.
	 * @param int $expires
	 *   Its lifetime in seconds.
	 *
	 * @return string
	 *   The response body.
	 */
	private function tokenBody(string $token, int $expires = 3600): string
	{
		return (string) json_encode([
			'access_token' => $token,
			'expires_in' => $expires,
			'token_type' => 'Bearer',
		]);
	}

	#endregion

	#region Assertions

	#[Test]
	#[TestDox('the assertion verifies against the public key with openssl')]
	#[Group('strata/gcs')]
	public function assertionVerifiesAgainstThePublicKey(): void
	{
		$assertion = $this->credentials()->assertion($this->now);
		$segments = explode('.', $assertion);

		$this->assertCount(3, $segments);

		$signature = base64_decode(strtr($segments[2], '-_', '+/'), true);

		$this->assertIsString($signature);
		$this->assertSame(
			1,
			openssl_verify(
				$segments[0] . '.' . $segments[1],
				$signature,
				$this->publicKey,
				OPENSSL_ALGO_SHA256,
			),
		);
	}

	#[Test]
	#[
		TestDox(
			'the header declares rs256 and the claims name the account, the scope and the audience',
		),
	]
	#[Group('strata/gcs')]
	public function claimsAreWhatGoogleExpects(): void
	{
		$segments = explode('.', $this->credentials()->assertion($this->now));
		$header = $this->decode($segments[0]);
		$claims = $this->decode($segments[1]);

		$this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);
		$this->assertSame(self::EMAIL, $claims['iss']);
		$this->assertSame(self::EMAIL, $claims['sub']);
		$this->assertSame(ServiceAccountCredentials::SCOPE, $claims['scope']);
		$this->assertSame(ServiceAccountCredentials::DEFAULT_TOKEN_URI, $claims['aud']);
		$this->assertSame($this->now, $claims['iat']);
		$this->assertSame($this->now + ServiceAccountCredentials::LIFETIME, $claims['exp']);
	}

	#[Test]
	#[TestDox('every segment is base64url, which is not base64')]
	#[Group('strata/gcs')]
	public function segmentsAreBase64Url(): void
	{
		$assertion = $this->credentials()->assertion($this->now);

		$this->assertStringNotContainsString('+', $assertion);
		$this->assertStringNotContainsString('/', $assertion);
		$this->assertStringNotContainsString('=', $assertion);
	}

	#[Test]
	#[TestDox('a key that will not sign is reported rather than producing an empty signature')]
	#[Group('strata/gcs')]
	public function unusableKeyIsReported(): void
	{
		$credentials = new ServiceAccountCredentials(
			self::EMAIL,
			"-----BEGIN PRIVATE KEY-----\nnot a key at all\n-----END PRIVATE KEY-----\n",
			new HttpTransport(new Client(['handler' => HandlerStack::create($this->handler)])),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('would not sign');

		$credentials->assertion($this->now);
	}

	#endregion

	#region Exchange

	#[Test]
	#[TestDox('the assertion is exchanged under the jwt-bearer grant and the token comes back')]
	#[Group('strata/gcs')]
	public function assertionIsExchangedForAToken(): void
	{
		$credentials = $this->credentials();
		$this->queue(200, $this->tokenBody('ya29.first'));

		$this->assertSame('ya29.first', $credentials->token());

		$request = $this->requests()[0];

		$this->assertSame('POST', $request->getMethod());
		$this->assertSame(
			ServiceAccountCredentials::DEFAULT_TOKEN_URI,
			(string) $request->getUri(),
		);
		$this->assertSame(
			'application/x-www-form-urlencoded',
			$request->getHeaderLine('Content-Type'),
		);

		parse_str((string) $request->getBody(), $form);

		$this->assertSame(ServiceAccountCredentials::GRANT_TYPE, $form['grant_type'] ?? null);
		$this->assertIsString($form['assertion'] ?? null);
		$this->assertCount(3, explode('.', (string) $form['assertion']));
	}

	#[Test]
	#[TestDox('a token is held until shortly before it expires and is not asked for twice')]
	#[Group('strata/gcs')]
	public function tokenIsCachedUntilItNearlyExpires(): void
	{
		$credentials = $this->credentials();
		$this->queue(200, $this->tokenBody('ya29.first', 3600));

		$this->assertSame('ya29.first', $credentials->token());

		$this->now += 3600 - ServiceAccountCredentials::SKEW - 1;

		$this->assertSame('ya29.first', $credentials->token());
		$this->assertCount(1, $this->requests(), 'a held token is not exchanged again');
	}

	#[Test]
	#[TestDox('a token is exchanged again once the skew window opens')]
	#[Group('strata/gcs')]
	public function tokenIsRefreshedInsideTheSkewWindow(): void
	{
		$credentials = $this->credentials();
		$this->queue(200, $this->tokenBody('ya29.first', 3600));
		$this->queue(200, $this->tokenBody('ya29.second', 3600));

		$this->assertSame('ya29.first', $credentials->token());

		$this->now += 3600 - ServiceAccountCredentials::SKEW;

		$this->assertSame('ya29.second', $credentials->token());
		$this->assertCount(2, $this->requests());
	}

	#[Test]
	#[TestDox('a refused exchange reports what the token endpoint said')]
	#[Group('strata/gcs')]
	public function refusedExchangeIsReported(): void
	{
		$credentials = $this->credentials();
		$this->queue(
			400,
			(string) json_encode([
				'error' => 'invalid_grant',
				'error_description' => 'Invalid JWT Signature.',
			]),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('answered 400 (invalid_grant Invalid JWT Signature.)');

		$credentials->token();
	}

	#[Test]
	#[
		TestDox(
			'an exchange answered without a token raises rather than authenticating with nothing',
		),
	]
	#[Group('strata/gcs')]
	public function exchangeWithoutATokenIsRefused(): void
	{
		$credentials = $this->credentials();
		$this->queue(200, '{"expires_in":3600}');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('without an access token');

		$credentials->token();
	}

	#[Test]
	#[TestDox('the account is named in the description and the key never is')]
	#[Group('strata/gcs')]
	public function descriptionNamesTheAccountOnly(): void
	{
		$description = $this->credentials()->describe();

		$this->assertStringContainsString(self::EMAIL, $description);
		$this->assertStringNotContainsString('PRIVATE KEY', $description);
	}

	#endregion

	#region Key files

	#[Test]
	#[TestDox('a downloaded key file is read for its address, its key and its token uri')]
	#[Group('strata/gcs')]
	public function keyFileIsRead(): void
	{
		$credentials = ServiceAccountCredentials::fromJson(
			(string) json_encode([
				'type' => 'service_account',
				'client_email' => self::EMAIL,
				'private_key' => $this->privateKey,
				'token_uri' => 'https://oauth2.example.test/token',
			]),
			new HttpTransport(new Client(['handler' => HandlerStack::create($this->handler)])),
			ServiceAccountCredentials::SCOPE,
			fn(): int => $this->now,
		);
		$claims = $this->decode(explode('.', $credentials->assertion($this->now))[1]);

		$this->assertSame('https://oauth2.example.test/token', $claims['aud']);
		$this->assertSame(self::EMAIL, $claims['iss']);
	}

	#[Test]
	#[TestDox('a key file that is not json is refused')]
	#[Group('strata/gcs')]
	public function nonJsonKeyFileIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('has to be a json object');

		ServiceAccountCredentials::fromJson(
			'-----BEGIN PRIVATE KEY-----',
			new HttpTransport(new Client()),
		);
	}

	#[Test]
	#[TestDox('a key file missing either field is refused')]
	#[Group('strata/gcs')]
	public function incompleteKeyFileIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('client_email and private_key');

		ServiceAccountCredentials::fromJson(
			(string) json_encode(['client_email' => self::EMAIL]),
			new HttpTransport(new Client()),
		);
	}

	#endregion

	#region Pre-issued tokens

	#[Test]
	#[TestDox('a pre-issued token is handed straight back and is never exchanged')]
	#[Group('strata/gcs')]
	public function preIssuedTokenIsUsedAsIs(): void
	{
		$token = new StaticAccessToken('ya29.workload-identity');

		$this->assertSame('ya29.workload-identity', $token->token());
		$this->assertSame('ya29.workload-identity', $token->token());
		$this->assertSame('a pre-issued access token', $token->describe());
		$this->assertSame([], $this->requests());
	}

	#[Test]
	#[TestDox('an empty pre-issued token is refused rather than sent as a bearer of nothing')]
	#[Group('strata/gcs')]
	public function emptyPreIssuedTokenIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be empty');

		new StaticAccessToken('  ');
	}

	#endregion
}
