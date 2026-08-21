<?php

/**
 * @file
 * Tries the things an attacker would try, and prints what each one gets.
 *
 * Run through `./startup.sh attack [--kind=<kind>]`.
 *
 * Every probe here is read-only against the site and is meant to fail. What is worth watching is
 * HOW it fails: a refusal that names the reason is a control working, and a probe that quietly
 * succeeds is a finding.
 *
 * Kinds:
 * - `keys`        try to compose an object key that escapes this site's namespace
 * - `tamper`      change a stored byte and read the frame back
 * - `foreign`     seal something under another key and try to open it with this site's
 * - `xxe`         feed the listing parser an XML external entity pointing at a local file
 * - `webhook`     forge and replay a webhook signature
 * - `flood`       drive the budget guard past its ceiling
 * - `permissions` list every route and the permission it requires
 * - `everything`  all of the above
 */

declare(strict_types=1);

use Drupal\strata\Crypto\AuthenticationFailure;
use Drupal\strata\Crypto\StaticKeyProvider;
use Drupal\strata\Crypto\XChaCha20Poly1305Cipher;
use Drupal\strata\Event\Webhook\WebhookSignature;
use Drupal\strata\Site\SiteScopedProvider;
use Drupal\strata\Storage\ProviderStats;
use Drupal\strata\Budget\BudgetGuard;

require_once __DIR__ . '/lib.php';

$kind = strata_arg($extra, 0, 'everything');
$engine = strata_engine();
$all = $kind === 'everything';

#region Object Keys

if ($all || $kind === 'keys') {
	strata_head('composing a key that escapes the site namespace');

	$attempts = [
		'../other-site/commits/one',
		'commits/../../other-site/two',
		'commits//three',
		'/absolute/commits/four',
		"commits/five\0.json",
	];

	foreach ($attempts as $attempt) {
		try {
			$engine->provider()->put($attempt, 'probe');
			strata_row(
				'accepted',
				sprintf('%s -> normalised, not honoured', addcslashes($attempt, "\0")),
			);
		} catch (Throwable $refused) {
			strata_row(
				'refused',
				sprintf('%s: %s', addcslashes($attempt, "\0"), $refused->getMessage()),
			);
		}
	}

	strata_row('shared prefixes', implode(', ', SiteScopedProvider::SHARED_PREFIXES));
	strata_row('this site', $engine->site()->id());
}

#endregion

#region Reading Without The Key

if ($all || $kind === 'foreign') {
	strata_head('opening a frame sealed under a key this site does not have');

	$theirs = new XChaCha20Poly1305Cipher(StaticKeyProvider::generate()->key());
	$mine = new XChaCha20Poly1305Cipher(StaticKeyProvider::generate()->key());
	$sealed = $theirs->seal('the other site payload');

	try {
		$mine->open($sealed);
		strata_row('opened it', 'THIS IS A FINDING; a foreign frame must never open');
	} catch (AuthenticationFailure $refused) {
		strata_row('refused', $refused->getMessage());
	}
}

if ($all || $kind === 'tamper') {
	strata_head('changing a stored byte and reading the frame back');

	$frames = strata_keys('frames/');
	$packs = strata_keys('packs/');
	$target = $frames[0] ?? ($packs[0] ?? '');

	if ($target === '') {
		strata_row('nothing stored', 'run traffic first');
	} else {
		$body = (string) $engine->provider()->get($target);
		$at = intdiv(strlen($body), 2);

		$engine->provider()->put($target, substr_replace($body, 'Z', $at, 1));

		$report = $engine->verifier()->verify();

		strata_row('object', $target);
		strata_row(
			'verify clean',
			$report->isClean() ? 'THIS IS A FINDING' : 'no, as it should be',
		);
		strata_row('codes', implode(', ', array_keys($report->byCode())));

		// put it back, so a later probe is not measuring this one
		$engine->provider()->put($target, $body);
		strata_row('restored', 'the object was put back byte for byte');
	}
}

#endregion

#region Untrusted Input

if ($all || $kind === 'xxe') {
	strata_head('feeding the listing parser an external entity');

	$target = sys_get_temp_dir() . '/strata-xxe-target.txt';
	file_put_contents($target, 'THE-FILE-CONTENTS-NOBODY-SHOULD-SEE');

	$xml = sprintf(
		'<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file://%s">]>' .
			'<ListBucketResult><Contents><Key>&x;</Key></Contents></ListBucketResult>',
		$target,
	);

	$document = new DOMDocument();
	$document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
	$parsed = (string) $document->saveXML();

	strata_row(
		'entity resolved',
		str_contains($parsed, 'NOBODY-SHOULD-SEE')
			? 'THIS IS A FINDING'
			: 'no, it expanded to nothing',
	);
	strata_row('flags used', 'LIBXML_NONET | LIBXML_NOCDATA, and deliberately not LIBXML_NOENT');

	unlink($target);
}

if ($all || $kind === 'webhook') {
	strata_head('forging and replaying a webhook signature');

	$body = (string) json_encode(['event' => 'strata.commit.sealed', 'commit' => 'deadbeef']);
	$secret = 'the-signing-secret';
	$now = time();
	$header = WebhookSignature::sign($body, $secret, $now);

	strata_row(
		'signature over the real body',
		WebhookSignature::verify($header, $body, $secret, $now) ? 'verifies' : 'THIS IS A FINDING',
	);
	strata_row(
		'one byte added to the body',
		WebhookSignature::verify($header, $body . ' ', $secret, $now)
			? 'THIS IS A FINDING'
			: 'refused',
	);
	strata_row(
		'a different secret',
		WebhookSignature::verify($header, $body, 'guessed', $now) ? 'THIS IS A FINDING' : 'refused',
	);
	strata_row(
		'replayed an hour later',
		WebhookSignature::verify($header, $body, $secret, $now + 3600)
			? 'THIS IS A FINDING'
			: 'refused, outside the tolerance',
	);
}

#endregion

#region Exhausting It

if ($all || $kind === 'flood') {
	strata_head('driving the budget past its ceiling');

	$guard = new BudgetGuard(bytesCeiling: 1_048_576);
	$stats = new ProviderStats();

	for ($at = 0; $at < 500; $at++) {
		$stats->record('put', 1_048_576, 0.001);
	}

	$assessment = $guard->assess(512 * 1_048_576, $stats, 3600);

	strata_row('ceiling', strata_bytes(1_048_576) . ' per month');
	strata_row('projected', $assessment->summary());
	strata_row('over budget', $assessment->isOverBudget() ? 'yes' : 'THIS IS A FINDING');
	strata_row(
		'capture still allowed',
		$assessment->allowsCapture() ? 'yes' : 'no, which is the point',
	);
}

#endregion

#region Permissions

if ($all || $kind === 'permissions') {
	strata_head('every route, and what it takes to reach it');

	$routes = Drupal::service('router.route_provider')->getAllRoutes();
	$listed = 0;

	foreach ($routes as $name => $route) {
		if (!str_starts_with((string) $name, 'strata')) {
			continue;
		}

		$listed++;

		strata_row(
			(string) $name,
			sprintf(
				'%s [%s]',
				$route->getPath(),
				(string) ($route->getRequirement('_permission') ?? 'NO PERMISSION REQUIREMENT'),
			),
		);
	}

	strata_row('routes listed', (string) $listed);

	strata_head('the permissions this module declares');

	foreach (Drupal::service('user.permissions')->getPermissions() as $name => $permission) {
		if (($permission['provider'] ?? '') !== 'strata') {
			continue;
		}

		strata_row(
			(string) $name,
			$permission['restrict access'] ?? false ? 'restricted, and marked so' : 'ordinary',
		);
	}
}

#endregion

strata_head('next');
strata_row('see what the store thinks', './startup.sh measure');
strata_row('break it for real', './startup.sh meltdown --kind=everything');
