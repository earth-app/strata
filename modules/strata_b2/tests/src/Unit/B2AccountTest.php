<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_b2\Unit;

use Drupal\strata\Storage\HttpTransport;
use Drupal\strata_b2\B2Account;
use Drupal\strata_b2\B2Authorization;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Proves the one call that decides where every other call goes is read correctly.
 *
 * B2 tells the client its api host, its download host and its part sizes at authorization time, and
 * all of them differ between accounts. Reading any of them wrongly sends every subsequent request
 * somewhere that does not exist, so this lane asserts against the documented response shape of both
 * api versions rather than against one account's answer.
 */
#[CoversClass(B2Account::class)]
#[CoversClass(B2Authorization::class)]
class B2AccountTest extends TestCase
{
	#region Fixtures

	/**
	 * Key id every fixture authorizes with.
	 */
	private const KEY_ID = '0022deadbeef0000000000001';

	/**
	 * Application key every fixture authorizes with.
	 */
	private const KEY = 'K002ExampleApplicationKeyValue';

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

	protected function setUp(): void
	{
		parent::setUp();

		$this->handler = new MockHandler();
		$this->history = [];
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
	 * The account under test.
	 *
	 * @return B2Account
	 *   An account over the recording transport.
	 */
	private function account(): B2Account
	{
		$stack = HandlerStack::create($this->handler);
		$stack->push(Middleware::history($this->history));

		return new B2Account(
			self::KEY_ID,
			self::KEY,
			new HttpTransport(new Client(['handler' => $stack])),
		);
	}

	/**
	 * The requests the account made.
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
	 * The documented version 2 response.
	 *
	 * @param string $token
	 *   The authorization token it carries.
	 *
	 * @return string
	 *   The response body.
	 */
	private function version2(string $token = '4_0022_first'): string
	{
		return (string) json_encode([
			'absoluteMinimumPartSize' => 5_000_000,
			'accountId' => 'deadbeef0000',
			'allowed' => [
				'bucketId' => 'bucket-id-1',
				'bucketName' => 'strata-backups',
				'capabilities' => ['listFiles', 'readFiles', 'writeFiles', 'deleteFiles'],
				'namePrefix' => null,
			],
			'apiUrl' => 'https://api002.backblazeb2.com',
			'authorizationToken' => $token,
			'downloadUrl' => 'https://f002.backblazeb2.com',
			'recommendedPartSize' => 100_000_000,
		]);
	}

	#endregion

	#region Reading the answer

	#[Test]
	#[TestDox('the documented version 2 answer is read field for field')]
	#[Group('strata/b2')]
	public function readsTheDocumentedVersion2Answer(): void
	{
		$this->queue(200, $this->version2());

		$authorization = $this->account()->authorization();

		$this->assertSame('https://api002.backblazeb2.com', $authorization->apiUrl);
		$this->assertSame('https://f002.backblazeb2.com', $authorization->downloadUrl);
		$this->assertSame('4_0022_first', $authorization->token);
		$this->assertSame(5_000_000, $authorization->minimumPartSize);
		$this->assertSame(100_000_000, $authorization->recommendedPartSize);
		$this->assertSame('bucket-id-1', $authorization->bucketId);
		$this->assertSame('', $authorization->namePrefix, 'a null prefix is no prefix');
		$this->assertTrue($authorization->isRestricted());
		$this->assertTrue($authorization->allows('writeFiles'));
		$this->assertFalse($authorization->allows('listBuckets'));
	}

	#[Test]
	#[TestDox('the version 3 answer nests the same fields and is read from there')]
	#[Group('strata/b2')]
	public function readsTheNestedVersion3Answer(): void
	{
		$this->queue(
			200,
			(string) json_encode([
				'accountId' => 'deadbeef0000',
				'authorizationToken' => '4_0022_v3',
				'apiInfo' => [
					'storageApi' => [
						'absoluteMinimumPartSize' => 5_000_000,
						'apiUrl' => 'https://api003.backblazeb2.com',
						'bucketId' => 'bucket-id-3',
						'bucketName' => 'strata-backups',
						'capabilities' => ['readFiles', 'writeFiles'],
						'downloadUrl' => 'https://f003.backblazeb2.com',
						'namePrefix' => '_strata/',
						'recommendedPartSize' => 100_000_000,
					],
				],
			]),
		);

		$authorization = $this->account()->authorization();

		$this->assertSame('https://api003.backblazeb2.com', $authorization->apiUrl);
		$this->assertSame('https://f003.backblazeb2.com', $authorization->downloadUrl);
		$this->assertSame('bucket-id-3', $authorization->bucketId);
		$this->assertSame('_strata/', $authorization->namePrefix);
		$this->assertTrue($authorization->allows('writeFiles'));
	}

	#[Test]
	#[TestDox('a key with no capability list is not read as a key with no permissions')]
	#[Group('strata/b2')]
	public function anUnlistedCapabilitySetAllowsEverything(): void
	{
		$authorization = B2Authorization::fromResponse([
			'apiUrl' => 'https://api002.backblazeb2.com',
			'downloadUrl' => 'https://f002.backblazeb2.com',
			'authorizationToken' => '4_0022',
		]);

		$this->assertTrue($authorization->allows('writeFiles'));
		$this->assertFalse($authorization->isRestricted());
		$this->assertSame(B2Authorization::FALLBACK_MINIMUM_PART, $authorization->minimumPartSize);
	}

	#[Test]
	#[TestDox('an api call and a download are addressed at the two hosts the answer named')]
	#[Group('strata/b2')]
	public function urlsComeFromTheAnswer(): void
	{
		$this->queue(200, $this->version2());

		$authorization = $this->account()->authorization();

		$this->assertSame(
			'https://api002.backblazeb2.com/b2api/v2/b2_get_upload_url',
			$authorization->endpoint('b2_get_upload_url'),
		);
		$this->assertSame(
			'https://f002.backblazeb2.com/file/strata-backups/_strata/site-1/a%20b',
			$authorization->fileUrl('strata-backups', '_strata/site-1/a b'),
		);
	}

	#endregion

	#region Authorizing

	#[Test]
	#[TestDox('the key travels as http basic and the call is made once')]
	#[Group('strata/b2')]
	public function theKeyTravelsAsBasicAuth(): void
	{
		$account = $this->account();
		$this->queue(200, $this->version2());

		$account->authorization();
		$account->authorization();

		$request = $this->requests()[0];

		$this->assertCount(1, $this->requests(), 'an authorization is held for the request');
		$this->assertStringEndsWith('/b2api/v2/b2_authorize_account', (string) $request->getUri());
		$this->assertSame(
			'Basic ' . base64_encode(self::KEY_ID . ':' . self::KEY),
			$request->getHeaderLine('Authorization'),
		);
	}

	#[Test]
	#[TestDox('re-authorizing throws the held answer away and asks again')]
	#[Group('strata/b2')]
	public function reauthorizingAsksAgain(): void
	{
		$account = $this->account();

		$this->queue(200, $this->version2('4_0022_first'));
		$this->queue(200, $this->version2('4_0022_second'));

		$this->assertSame('4_0022_first', $account->authorization()->token);
		$this->assertSame('4_0022_second', $account->reauthorize()->token);
		$this->assertSame('4_0022_second', $account->authorization()->token);
		$this->assertCount(2, $this->requests());
	}

	#[Test]
	#[TestDox('a refused key is reported with what backblaze said and never with the key')]
	#[Group('strata/b2')]
	public function refusedKeyIsReported(): void
	{
		$account = $this->account();
		$this->queue(
			401,
			(string) json_encode([
				'status' => 401,
				'code' => 'bad_auth_token',
				'message' => 'Invalid authorization token',
			]),
		);

		try {
			$account->authorization();
			$this->fail('a refused key has to raise');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('answered 401', $e->getMessage());
			$this->assertStringContainsString('bad_auth_token', $e->getMessage());
			$this->assertStringNotContainsString(self::KEY, $e->getMessage());
		}
	}

	#[Test]
	#[TestDox('an answer naming no api url is refused rather than addressing nothing')]
	#[Group('strata/b2')]
	public function answerWithNoApiUrlIsRefused(): void
	{
		$account = $this->account();
		$this->queue(200, '{"authorizationToken":"4_0022"}');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('answered without an api url');

		$account->authorization();
	}

	#[Test]
	#[TestDox('the description names the key id and never the key')]
	#[Group('strata/b2')]
	public function descriptionNamesTheKeyIdOnly(): void
	{
		$description = $this->account()->describe();

		$this->assertStringContainsString(self::KEY_ID, $description);
		$this->assertStringNotContainsString(self::KEY, $description);
	}

	#[Test]
	#[TestDox('half a key is refused at construction')]
	#[Group('strata/b2')]
	public function halfAKeyIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('key id and an application key');

		new B2Account(self::KEY_ID, '', new HttpTransport(new Client()));
	}

	#endregion
}
