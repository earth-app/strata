<?php

declare(strict_types=1);

namespace Drupal\strata_azure;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * How one account authenticates to the blob service.
 *
 * Two paths, and both are first-class. A **shared key** is the account key, which signs every
 * request with HMAC-SHA256 and grants everything the account can do. A **SAS token** is a query
 * string somebody else minted, scoped to a container, a permission set and an expiry, and it is what
 * an operator who will not hand a Drupal site the account key gives it instead.
 *
 * Both are secrets. Neither is rendered back into the settings form once it is stored. When both are
 * configured the account key signs, because a signature is computed per request and a token can
 * expire with nothing to say so but a 403.
 *
 * @see SharedKeySigner
 * @see AzureStorageProvider
 */
final class AzureCredentials
{
	/**
	 * The SAS token with no leading question mark.
	 */
	public readonly string $sasQuery;

	/**
	 * Constructs a credential set.
	 *
	 * @param string $accountName
	 *   Storage account name, which the shared key signature names and a SAS token does not.
	 * @param string $accountKey
	 *   The account key, base64 as the portal prints it, or an empty string when a SAS token is used.
	 * @param string $sasToken
	 *   A shared access signature, with or without its leading `?`, or an empty string when the
	 *   account key is used.
	 *
	 * @throws InvalidArgumentException
	 *   When the account name is empty, neither credential is given, or the account key is not
	 *   base64. A key that is not base64 produces a signature the service rejects with no
	 *   indication of why, so it is refused here instead.
	 */
	public function __construct(
		public readonly string $accountName,
		#[SensitiveParameter] public readonly string $accountKey = '',
		#[SensitiveParameter] string $sasToken = '',
	) {
		$sas = ltrim(trim($sasToken), '?');

		if (trim($accountName) === '') {
			throw new InvalidArgumentException('Azure credentials need an account name');
		}
		if (trim($accountKey) === '' && $sas === '') {
			throw new InvalidArgumentException(
				'Azure credentials need either an account key or a sas token',
			);
		}
		if (trim($accountKey) !== '' && base64_decode($accountKey, true) === false) {
			throw new InvalidArgumentException('An azure account key has to be base64');
		}

		$this->sasQuery = $sas;
	}

	/**
	 * Whether requests are signed with the account key.
	 *
	 * @return bool
	 *   TRUE when an account key is available.
	 */
	public function hasSharedKey(): bool
	{
		return trim($this->accountKey) !== '';
	}

	/**
	 * Whether requests carry a shared access signature instead of being signed.
	 *
	 * @return bool
	 *   TRUE when a SAS token is available.
	 */
	public function hasSas(): bool
	{
		return $this->sasQuery !== '';
	}

	/**
	 * The raw bytes the signature is computed with.
	 *
	 * @return string
	 *   The decoded account key, or an empty string when there is none.
	 */
	public function signingKey(): string
	{
		$decoded = base64_decode($this->accountKey, true);

		return $decoded === false ? '' : $decoded;
	}

	/**
	 * Which credential is in use, for a message that must not print the credential itself.
	 *
	 * @return string
	 *   A short description.
	 */
	public function describe(): string
	{
		return $this->hasSharedKey() ? 'the account key' : 'a sas token';
	}
}
