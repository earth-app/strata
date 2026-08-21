<?php

declare(strict_types=1);

namespace Drupal\strata_s3\Credentials;

/**
 * Reads credentials from the shared AWS ini files.
 *
 * `~/.aws/credentials` holds key pairs under a bare section name; `~/.aws/config` holds settings
 * for the same profile under `[profile <name>]`. Both are read, and the credentials file wins where
 * a key appears in both, which is the precedence every AWS tool uses.
 *
 * The profile is chosen from the explicit constructor argument, then `AWS_PROFILE`, then `default`.
 * A profile that names a `source_profile` and no keys of its own takes the source profile's keys,
 * one level deep.
 *
 * A profile that also names a `role_arn` resolves to nothing. Assuming that role needs an STS call,
 * and handing back the source profile's keys instead would sign every request as the wrong
 * principal while appearing to work.
 *
 * @see CredentialChain
 */
final class SharedFileCredentials implements CredentialProviderInterface
{
	/**
	 * Variable naming the profile to use.
	 */
	public const PROFILE = 'AWS_PROFILE';

	/**
	 * Profile used when none is named.
	 */
	public const DEFAULT_PROFILE = 'default';

	/**
	 * The environment to read the profile name from.
	 *
	 * @var array<string, string>
	 */
	private readonly array $environment;

	/**
	 * Sections of both files, merged, keyed by profile name.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private ?array $profiles = null;

	/**
	 * Constructs a provider.
	 *
	 * @param string $credentialsPath
	 *   Path to the credentials file, conventionally `~/.aws/credentials`.
	 * @param string $configPath
	 *   Path to the config file, conventionally `~/.aws/config`.
	 * @param string|null $profile
	 *   Profile to read, or NULL to take it from `AWS_PROFILE` and fall back to `default`.
	 * @param array<string, string>|null $environment
	 *   The environment to read the profile name from, or NULL to use getenv().
	 */
	public function __construct(
		private readonly string $credentialsPath,
		private readonly string $configPath,
		private readonly ?string $profile = null,
		?array $environment = null,
	) {
		$this->environment = $environment ?? getenv();
	}

	/**
	 * The profile this provider reads.
	 *
	 * @return string
	 *   The explicit profile, the one named by `AWS_PROFILE`, or `default`.
	 */
	public function profile(): string
	{
		$named = $this->profile ?? ($this->environment[self::PROFILE] ?? '');

		return trim($named) === '' ? self::DEFAULT_PROFILE : trim($named);
	}

	/**
	 * {@inheritdoc}
	 */
	public function resolve(): ?Credentials
	{
		return $this->credentialsFor($this->profile(), true);
	}

	/**
	 * {@inheritdoc}
	 */
	public function describe(): string
	{
		return sprintf('Shared file %s (profile %s)', $this->credentialsPath, $this->profile());
	}

	#region Reading

	/**
	 * Credentials for one profile.
	 *
	 * @param string $name
	 *   Profile name.
	 * @param bool $follow
	 *   Whether a `source_profile` may be followed. FALSE on the second hop, which bounds the chain
	 *   to one level and makes a cycle unreachable.
	 *
	 * @return Credentials|null
	 *   The credentials, or NULL when the profile is absent, incomplete, or needs a role assumed.
	 */
	private function credentialsFor(string $name, bool $follow): ?Credentials
	{
		$profile = $this->profiles()[$name] ?? null;

		if ($profile === null) {
			return null;
		}

		$keyId = trim($profile['aws_access_key_id'] ?? '');
		$secret = trim($profile['aws_secret_access_key'] ?? '');

		if ($keyId !== '' && $secret !== '') {
			$token = trim($profile['aws_session_token'] ?? '');

			return new Credentials($keyId, $secret, $token === '' ? null : $token);
		}

		$source = trim($profile['source_profile'] ?? '');

		if (!$follow || $source === '' || $source === $name) {
			return null;
		}
		// assuming the role needs an STS call, and the source profile's identity is not the role's
		if (trim($profile['role_arn'] ?? '') !== '') {
			return null;
		}

		return $this->credentialsFor($source, false);
	}

	/**
	 * Both files, parsed and merged.
	 *
	 * @return array<string, array<string, string>>
	 *   Settings keyed by profile name, with the credentials file taking precedence.
	 */
	private function profiles(): array
	{
		if ($this->profiles !== null) {
			return $this->profiles;
		}

		$merged = $this->parse($this->configPath);

		foreach ($this->parse($this->credentialsPath) as $name => $settings) {
			$merged[$name] = [...$merged[$name] ?? [], ...$settings];
		}

		return $this->profiles = $merged;
	}

	/**
	 * Parses one ini file.
	 *
	 * Hand-parsed rather than handed to parse_ini_file(), which drops a value containing an
	 * unquoted `#` and cannot express the `[profile name]` section form.
	 *
	 * @param string $path
	 *   Path to read. An absent or unreadable file parses as empty.
	 *
	 * @return array<string, array<string, string>>
	 *   Settings keyed by profile name, with keys lowercased.
	 */
	private function parse(string $path): array
	{
		if (!is_file($path) || !is_readable($path)) {
			return [];
		}

		$contents = @file_get_contents($path);

		if ($contents === false) {
			return [];
		}

		$profiles = [];
		$section = null;

		foreach (preg_split('/\R/', $contents) ?: [] as $line) {
			$line = trim($line);

			if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
				continue;
			}
			if (str_starts_with($line, '[') && str_ends_with($line, ']')) {
				$section = $this->sectionName(substr($line, 1, -1));

				continue;
			}

			$at = strpos($line, '=');

			if ($section === null || $at === false || $at === 0) {
				continue;
			}

			$key = strtolower(trim(substr($line, 0, $at)));
			$profiles[$section][$key] = trim(substr($line, $at + 1));
		}

		return $profiles;
	}

	/**
	 * Reduces a section header to a profile name.
	 *
	 * @param string $header
	 *   Section header with its brackets removed.
	 *
	 * @return string
	 *   The profile name, with the `profile ` prefix the config file uses removed.
	 */
	private function sectionName(string $header): string
	{
		$header = trim($header);

		return str_starts_with($header, 'profile ') ? trim(substr($header, 8)) : $header;
	}

	#endregion
}
