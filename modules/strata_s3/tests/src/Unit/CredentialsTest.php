<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_s3\Unit;

use Drupal\strata_s3\Credentials\CredentialChain;
use Drupal\strata_s3\Credentials\CredentialProviderInterface;
use Drupal\strata_s3\Credentials\Credentials;
use Drupal\strata_s3\Credentials\EnvironmentCredentials;
use Drupal\strata_s3\Credentials\InstanceProfileCredentials;
use Drupal\strata_s3\Credentials\SharedFileCredentials;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Credentials::class)]
#[CoversClass(EnvironmentCredentials::class)]
#[CoversClass(SharedFileCredentials::class)]
#[CoversClass(InstanceProfileCredentials::class)]
#[CoversClass(CredentialChain::class)]
class CredentialsTest extends TestCase
{
	#region Fixtures

	/**
	 * Files written during a test, removed afterwards.
	 *
	 * @var list<string>
	 */
	private array $files = [];

	/**
	 * Requests the fake metadata transport recorded.
	 *
	 * @var list<array{method: string, url: string, headers: array<string, string>}>
	 */
	private array $sent = [];

	protected function tearDown(): void
	{
		foreach ($this->files as $path) {
			@unlink($path);
		}

		$this->files = [];
		$this->sent = [];

		parent::tearDown();
	}

	/**
	 * Writes a temporary file and registers it for cleanup.
	 *
	 * @param string $contents
	 *   What to write.
	 *
	 * @return string
	 *   The path.
	 */
	private function file(string $contents): string
	{
		$path = sys_get_temp_dir() . '/strata-aws-' . bin2hex(random_bytes(8));
		file_put_contents($path, $contents);
		$this->files[] = $path;

		return $path;
	}

	/**
	 * A path nothing was written to.
	 *
	 * @return string
	 *   A path that does not exist.
	 */
	private function missing(): string
	{
		return sys_get_temp_dir() . '/strata-aws-absent-' . bin2hex(random_bytes(8));
	}

	/**
	 * A transport that answers metadata requests from a map of url to response.
	 *
	 * @param array<string, array{status: int, body: string}> $responses
	 *   Responses keyed by the url path they answer.
	 *
	 * @return callable
	 *   The transport.
	 */
	private function metadata(array $responses): callable
	{
		return function (
			string $method,
			string $url,
			array $headers,
			?string $body,
		) use ($responses): array {
			$this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
			$path = (string) parse_url($url, PHP_URL_PATH);

			return $responses[$path] ?? ['status' => 404, 'body' => ''];
		};
	}

	/**
	 * A provider that always answers the same way.
	 *
	 * @param Credentials|null $credentials
	 *   What to answer with.
	 * @param string $name
	 *   What the source calls itself.
	 *
	 * @return CredentialProviderInterface
	 *   The provider.
	 */
	private function fixed(?Credentials $credentials, string $name): CredentialProviderInterface
	{
		return new class ($credentials, $name) implements CredentialProviderInterface {
			public function __construct(
				private readonly ?Credentials $credentials,
				private readonly string $name,
			) {}

			public function resolve(): ?Credentials
			{
				return $this->credentials;
			}

			public function describe(): string
			{
				return $this->name;
			}
		};
	}

	#endregion

	#region Value object

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function incompletePairProvider(): array
	{
		return [
			'no key id' => ['', 'secret', 'access key id'],
			'a blank key id' => ['   ', 'secret', 'access key id'],
			'no secret' => ['AKIDEXAMPLE', '', 'secret access key'],
		];
	}

	#[Test]
	#[TestDox('credentials with $_dataName are refused at construction')]
	#[Group('strata/s3')]
	#[DataProvider('incompletePairProvider')]
	public function refusesIncompletePairs(string $keyId, string $secret, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		new Credentials($keyId, $secret);
	}

	#[Test]
	#[TestDox('a long-lived key pair never expires')]
	#[Group('strata/s3')]
	public function longLivedKeysNeverExpire(): void
	{
		$credentials = new Credentials('AKIDEXAMPLE', 'secret');

		$this->assertFalse($credentials->isExpired(0));
		$this->assertFalse($credentials->isExpired(PHP_INT_MAX));
		$this->assertFalse($credentials->hasSessionToken());
	}

	#[Test]
	#[TestDox('a session is spent a margin before the moment it lapses')]
	#[Group('strata/s3')]
	public function sessionsExpireEarly(): void
	{
		$credentials = new Credentials('AKIDEXAMPLE', 'secret', 'TOKEN', 1000);

		$this->assertTrue($credentials->hasSessionToken());
		$this->assertFalse($credentials->isExpired(1000 - Credentials::EXPIRY_MARGIN - 1));
		$this->assertTrue($credentials->isExpired(1000 - Credentials::EXPIRY_MARGIN));
		$this->assertTrue($credentials->isExpired(2000));
	}

	#endregion

	#region Environment

	#[Test]
	#[TestDox('the environment provider reads the three variables every aws tool agrees on')]
	#[Group('strata/s3')]
	public function readsCredentialsFromTheEnvironment(): void
	{
		$provider = new EnvironmentCredentials([
			'AWS_ACCESS_KEY_ID' => 'AKIDEXAMPLE',
			'AWS_SECRET_ACCESS_KEY' => 'secret',
			'AWS_SESSION_TOKEN' => 'TOKEN',
		]);
		$credentials = $provider->resolve();

		$this->assertInstanceOf(Credentials::class, $credentials);
		$this->assertSame('AKIDEXAMPLE', $credentials->accessKeyId);
		$this->assertSame('secret', $credentials->secretAccessKey);
		$this->assertSame('TOKEN', $credentials->sessionToken);
		$this->assertStringContainsString('AWS_ACCESS_KEY_ID', $provider->describe());
	}

	/**
	 * @return array<string, array{array<string, string>}>
	 */
	public static function partialEnvironmentProvider(): array
	{
		return [
			'an empty environment' => [[]],
			'only a key id' => [['AWS_ACCESS_KEY_ID' => 'AKIDEXAMPLE']],
			'only a secret' => [['AWS_SECRET_ACCESS_KEY' => 'secret']],
			'a blank secret' => [
				['AWS_ACCESS_KEY_ID' => 'AKIDEXAMPLE', 'AWS_SECRET_ACCESS_KEY' => '  '],
			],
		];
	}

	#[Test]
	#[TestDox('the environment provider reports nothing from $_dataName')]
	#[Group('strata/s3')]
	#[DataProvider('partialEnvironmentProvider')]
	public function reportsNothingFromPartialEnvironment(array $environment): void
	{
		$this->assertNull((new EnvironmentCredentials($environment))->resolve());
	}

	#endregion

	#region Shared files

	#[Test]
	#[TestDox('the shared file provider reads a key pair out of the default profile')]
	#[Group('strata/s3')]
	public function readsTheDefaultProfile(): void
	{
		$path = $this->file(
			implode("\n", [
				'; a comment',
				'[default]',
				'aws_access_key_id = AKIDEXAMPLE',
				'aws_secret_access_key = secret',
				'aws_session_token = TOKEN',
				'',
				'[other]',
				'aws_access_key_id = OTHERKEY',
				'aws_secret_access_key = othersecret',
			]),
		);

		$provider = new SharedFileCredentials($path, $this->missing(), null, []);
		$credentials = $provider->resolve();

		$this->assertInstanceOf(Credentials::class, $credentials);
		$this->assertSame('AKIDEXAMPLE', $credentials->accessKeyId);
		$this->assertSame('TOKEN', $credentials->sessionToken);
		$this->assertSame('default', $provider->profile());
		$this->assertStringContainsString('profile default', $provider->describe());
	}

	#[Test]
	#[TestDox('an explicit profile beats the one named in the environment')]
	#[Group('strata/s3')]
	public function explicitProfileWins(): void
	{
		$path = $this->file(
			implode("\n", [
				'[default]',
				'aws_access_key_id = DEFAULTKEY',
				'aws_secret_access_key = secret',
				'',
				'[staging]',
				'aws_access_key_id = STAGINGKEY',
				'aws_secret_access_key = secret',
				'',
				'[live]',
				'aws_access_key_id = LIVEKEY',
				'aws_secret_access_key = secret',
			]),
		);
		$environment = ['AWS_PROFILE' => 'staging'];

		$fromEnvironment = new SharedFileCredentials($path, $this->missing(), null, $environment);
		$explicit = new SharedFileCredentials($path, $this->missing(), 'live', $environment);

		$this->assertSame('STAGINGKEY', $fromEnvironment->resolve()?->accessKeyId);
		$this->assertSame('LIVEKEY', $explicit->resolve()?->accessKeyId);
	}

	#[Test]
	#[TestDox('the credentials file wins where a profile appears in both files')]
	#[Group('strata/s3')]
	public function credentialsFileBeatsConfig(): void
	{
		$credentials = $this->file(
			implode("\n", ['[live]', 'aws_secret_access_key = fromcredentials']),
		);
		$config = $this->file(
			implode("\n", [
				'[profile live]',
				'region = eu-west-2',
				'aws_access_key_id = AKIDEXAMPLE',
				'aws_secret_access_key = fromconfig',
			]),
		);

		$resolved = (new SharedFileCredentials($credentials, $config, 'live', []))->resolve();

		$this->assertInstanceOf(Credentials::class, $resolved);
		$this->assertSame('AKIDEXAMPLE', $resolved->accessKeyId);
		$this->assertSame('fromcredentials', $resolved->secretAccessKey);
	}

	#[Test]
	#[TestDox('a profile with no keys of its own takes them from its source profile')]
	#[Group('strata/s3')]
	public function followsSourceProfileOneLevel(): void
	{
		$path = $this->file(
			implode("\n", [
				'[base]',
				'aws_access_key_id = BASEKEY',
				'aws_secret_access_key = basesecret',
				'',
				'[derived]',
				'source_profile = base',
				'region = us-west-2',
			]),
		);

		$resolved = (new SharedFileCredentials($path, $this->missing(), 'derived', []))->resolve();

		$this->assertSame('BASEKEY', $resolved?->accessKeyId);
	}

	#[Test]
	#[TestDox('a profile that needs a role assumed resolves to nothing rather than to the source')]
	#[Group('strata/s3')]
	public function refusesToImpersonateSourceProfileForRole(): void
	{
		$path = $this->file(
			implode("\n", [
				'[base]',
				'aws_access_key_id = BASEKEY',
				'aws_secret_access_key = basesecret',
				'',
				'[assumed]',
				'source_profile = base',
				'role_arn = arn:aws:iam::123456789012:role/strata',
			]),
		);

		$this->assertNull(
			(new SharedFileCredentials($path, $this->missing(), 'assumed', []))->resolve(),
		);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function unusableProfileProvider(): array
	{
		return [
			'a profile that is not there' => ["[other]\naws_access_key_id = K", 'absent'],
			'a profile with only a key id' => ["[default]\naws_access_key_id = K", 'default'],
			'a source profile pointing at itself' => ["[loop]\nsource_profile = loop", 'loop'],
			'a source profile that is not there' => [
				"[derived]\nsource_profile = nowhere",
				'derived',
			],
		];
	}

	#[Test]
	#[TestDox('the shared file provider reports nothing for $_dataName')]
	#[Group('strata/s3')]
	#[DataProvider('unusableProfileProvider')]
	public function reportsNothingForUnusableProfiles(string $ini, string $profile): void
	{
		$path = $this->file($ini);

		$this->assertNull(
			(new SharedFileCredentials($path, $this->missing(), $profile, []))->resolve(),
		);
	}

	#[Test]
	#[TestDox('lines outside a profile and lines with no assignment are ignored')]
	#[Group('strata/s3')]
	public function ignoresStrayIniLines(): void
	{
		$path = $this->file(
			implode("\n", [
				'stray = value',
				'[default]',
				'this line carries no assignment',
				'= no key at all',
				'aws_access_key_id = AKIDEXAMPLE',
				'aws_secret_access_key = secret',
			]),
		);

		$resolved = (new SharedFileCredentials($path, $this->missing(), null, []))->resolve();

		$this->assertSame('AKIDEXAMPLE', $resolved?->accessKeyId);
	}

	#[Test]
	#[TestDox('an absent credentials file is not an error')]
	#[Group('strata/s3')]
	public function absentFilesResolveToNothing(): void
	{
		$provider = new SharedFileCredentials($this->missing(), $this->missing(), null, []);

		$this->assertNull($provider->resolve());
		$this->assertSame('default', $provider->profile());
	}

	#endregion

	#region Instance metadata

	#[Test]
	#[TestDox('the metadata provider walks the imdsv2 token, role and credentials exchange')]
	#[Group('strata/s3')]
	public function walksTheMetadataExchange(): void
	{
		$document = json_encode([
			'AccessKeyId' => 'ASIAEXAMPLE',
			'SecretAccessKey' => 'sessionsecret',
			'Token' => 'SESSIONTOKEN',
			'Expiration' => '2026-08-19T12:00:00Z',
		]);

		$provider = new InstanceProfileCredentials(
			$this->metadata([
				'/latest/api/token' => ['status' => 200, 'body' => 'IMDSTOKEN'],
				'/latest/meta-data/iam/security-credentials/' => [
					'status' => 200,
					'body' => "strata-backup-role\n",
				],
				'/latest/meta-data/iam/security-credentials/strata-backup-role' => [
					'status' => 200,
					'body' => (string) $document,
				],
			]),
		);
		$credentials = $provider->resolve();

		$this->assertInstanceOf(Credentials::class, $credentials);
		$this->assertSame('ASIAEXAMPLE', $credentials->accessKeyId);
		$this->assertSame('SESSIONTOKEN', $credentials->sessionToken);
		$this->assertSame(strtotime('2026-08-19T12:00:00Z'), $credentials->expiresAt);

		$this->assertCount(3, $this->sent);
		$this->assertSame('PUT', $this->sent[0]['method']);
		$this->assertSame(
			(string) InstanceProfileCredentials::TOKEN_TTL,
			$this->sent[0]['headers'][InstanceProfileCredentials::TTL_HEADER],
		);
		$this->assertSame('GET', $this->sent[1]['method']);

		// steps two and three are authenticated by the token step one issued
		foreach ([1, 2] as $step) {
			$this->assertSame(
				'IMDSTOKEN',
				$this->sent[$step]['headers'][InstanceProfileCredentials::TOKEN_HEADER],
			);
		}

		$this->assertStringContainsString('169.254.169.254', $provider->describe());
	}

	/**
	 * @return array<string, array{array<string, array{status: int, body: string}>, int}>
	 */
	public static function brokenMetadataProvider(): array
	{
		$token = ['/latest/api/token' => ['status' => 200, 'body' => 'IMDSTOKEN']];
		$role = [
			'/latest/meta-data/iam/security-credentials/' => ['status' => 200, 'body' => 'role'],
		];

		return [
			'imdsv1 being all that answers' => [[], 1],
			'a token step that answers empty' => [
				['/latest/api/token' => ['status' => 200, 'body' => '  ']],
				1,
			],
			'no role attached to the instance' => [$token, 2],
			'a credentials document that is not there' => [[...$token, ...$role], 3],
			'a credentials document that is not json' => [
				[
					...$token,
					...$role,
					'/latest/meta-data/iam/security-credentials/role' => [
						'status' => 200,
						'body' => 'not json',
					],
				],
				3,
			],
			'a credentials document missing the secret' => [
				[
					...$token,
					...$role,
					'/latest/meta-data/iam/security-credentials/role' => [
						'status' => 200,
						'body' => '{"AccessKeyId":"ASIAEXAMPLE"}',
					],
				],
				3,
			],
		];
	}

	#[Test]
	#[TestDox('the metadata provider reports nothing given $_dataName')]
	#[Group('strata/s3')]
	#[DataProvider('brokenMetadataProvider')]
	public function reportsNothingFromBrokenMetadata(array $responses, int $requests): void
	{
		$this->assertNull((new InstanceProfileCredentials($this->metadata($responses)))->resolve());
		$this->assertCount($requests, $this->sent);
	}

	#[Test]
	#[TestDox('a transport that fails reads as this source having nothing')]
	#[Group('strata/s3')]
	public function unreachableMetadataResolvesToNothing(): void
	{
		$provider = new InstanceProfileCredentials(static function (): array {
			throw new RuntimeException('Network is unreachable');
		});

		$this->assertNull($provider->resolve());
	}

	#endregion

	#region Chain

	#[Test]
	#[TestDox('the chain returns the first source that answers and names it')]
	#[Group('strata/s3')]
	public function chainTakesTheFirstAnswer(): void
	{
		$chain = new CredentialChain([
			$this->fixed(null, 'the empty source'),
			$this->fixed(new Credentials('SECONDKEY', 'secret'), 'the second source'),
			$this->fixed(new Credentials('THIRDKEY', 'secret'), 'the third source'),
		]);

		$this->assertSame('SECONDKEY', $chain->resolve()?->accessKeyId);
		$this->assertSame('the second source', $chain->describe());
	}

	#[Test]
	#[TestDox('a source answering with a spent session is skipped like an empty one')]
	#[Group('strata/s3')]
	public function chainSkipsSpentSessions(): void
	{
		$chain = new CredentialChain(
			[
				$this->fixed(
					new Credentials('EXPIREDKEY', 'secret', 'TOKEN', 500),
					'the spent one',
				),
				$this->fixed(new Credentials('LIVEKEY', 'secret'), 'the live one'),
			],
			static fn(): int => 1000,
		);

		$this->assertSame('LIVEKEY', $chain->resolve()?->accessKeyId);
		$this->assertSame('the live one', $chain->describe());
	}

	#[Test]
	#[TestDox('the winner is cached until it expires and then resolved again')]
	#[Group('strata/s3')]
	public function chainCachesUntilExpiry(): void
	{
		$now = 1000;
		$source = new class (0) implements CredentialProviderInterface {
			public function __construct(public int $calls) {}

			public function resolve(): Credentials
			{
				$this->calls++;

				return new Credentials('ASIAEXAMPLE', 'secret', 'TOKEN', 2000);
			}

			public function describe(): string
			{
				return 'the counted source';
			}
		};
		$chain = new CredentialChain([$source], static function () use (&$now): int {
			return $now;
		});

		$chain->resolve();
		$chain->resolve();
		$chain->resolve();

		$this->assertSame(1, $source->calls, 'a live session is resolved once');

		// past the margin the session is spent, so the source is asked again
		$now = 2000;
		$chain->resolve();

		$this->assertSame(2, $source->calls);

		$chain->reset();
		$chain->resolve();

		$this->assertSame(3, $source->calls);
	}

	#[Test]
	#[TestDox('every source reporting nothing is a configuration gap rather than an exception')]
	#[Group('strata/s3')]
	public function emptyChainResolvesToNothing(): void
	{
		$chain = new CredentialChain([
			$this->fixed(null, 'the first source'),
			$this->fixed(null, 'the second source'),
		]);

		$this->assertNull($chain->resolve());
		$this->assertSame('Credential chain over 2 sources, none resolved', $chain->describe());
	}

	#[Test]
	#[TestDox('a chain with no sources at all resolves to nothing')]
	#[Group('strata/s3')]
	public function chainWithNoSourcesResolvesToNothing(): void
	{
		$chain = new CredentialChain([]);

		$this->assertNull($chain->resolve());
		$this->assertStringContainsString('none resolved', $chain->describe());
	}

	#endregion
}
