<?php

declare(strict_types=1);

namespace Drupal\strata\Tree;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Storage\StorageProviderInterface;
use RuntimeException;

/**
 * Builds a tree describing the site, reusing every subtree that did not change.
 *
 * Subjects are addressed as `realm/name`, so a tree has one branch per realm and a leaf per
 * subject. Building a new tree from a previous one plus a set of changed subjects rewrites only the
 * branches on the path from those leaves to the root; every other subtree keeps its address and is
 * not written again.
 *
 * On a 262,000-subject site 1.31% of subjects change in an hour, so an hourly anchor writes the
 * leaves that changed plus one branch per affected realm plus the root, rather than a copy of the
 * site. The cost model depends on that.
 *
 * @see MerkleNode
 * @see Commit
 */
final class TreeBuilder
{
	/**
	 * Key prefix tree nodes are written under.
	 */
	public const PREFIX = 'trees';

	/**
	 * Nodes written during the current build, keyed by address.
	 *
	 * @var array<string, MerkleNode>
	 */
	private array $written = [];

	/**
	 * Nodes that were reused from the previous tree rather than written.
	 */
	private int $reused = 0;

	/**
	 * Constructs a builder.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where nodes are written.
	 */
	public function __construct(private readonly StorageProviderInterface $provider) {}

	/**
	 * Builds a tree from a complete set of subjects.
	 *
	 * Used for the first anchor, when there is no previous tree to share with.
	 *
	 * @param array<string, array{frames: list<string>, size: int}> $subjects
	 *   Subject path keyed to its frame map and decoded size. A path is `realm/name`.
	 *
	 * @return string
	 *   Address of the root node.
	 *
	 * @throws RuntimeException
	 *   When a node cannot be written.
	 */
	public function build(array $subjects): string
	{
		$this->written = [];
		$this->reused = 0;

		return $this->writeBranch('', $this->group($subjects));
	}

	/**
	 * Builds a tree from a previous one plus the subjects that changed.
	 *
	 * A subject mapped to NULL is deleted and its leaf disappears. Everything absent from $changed
	 * keeps whatever the previous tree said about it, by address, without being read.
	 *
	 * @param string $previousRoot
	 *   Address of the previous root node.
	 * @param array<string, array{frames: list<string>, size: int}|null> $changed
	 *   Subject path keyed to its new frame map and size, or NULL to remove it.
	 *
	 * @return string
	 *   Address of the new root node.
	 *
	 * @throws RuntimeException
	 *   When the previous root is absent or a node cannot be written.
	 */
	public function rebuild(string $previousRoot, array $changed): string
	{
		$this->written = [];
		$this->reused = 0;

		$root = $this->load($previousRoot);
		$grouped = $this->group(array_filter($changed, static fn(?array $s): bool => $s !== null));
		$removed = $this->group(
			array_map(
				static fn(): array => ['frames' => [], 'size' => 0],
				array_filter($changed, static fn(?array $s): bool => $s === null),
			),
		);

		$children = $root->children;
		$realms = array_unique([...array_keys($grouped), ...array_keys($removed)]);

		foreach ($realms as $realm) {
			$existing = isset($children[$realm]) ? $this->load($children[$realm]) : null;
			$leaves = $existing === null ? [] : $existing->children;

			foreach ($grouped[$realm] ?? [] as $name => $subject) {
				$leaves[$name] = $this->writeNode(
					MerkleNode::leaf($name, $subject['frames'], $subject['size']),
				);
			}
			foreach (array_keys($removed[$realm] ?? []) as $name) {
				unset($leaves[$name]);
			}

			if ($leaves === []) {
				unset($children[$realm]);

				continue;
			}

			$children[$realm] = $this->writeBranchFromLeaves($realm, $leaves);
		}

		// realms nobody touched keep their address, so their whole subtree is shared
		foreach ($children as $realm => $address) {
			if (!in_array($realm, $realms, true)) {
				$this->reused++;
			}
		}

		return $this->writeRoot($children);
	}

	/**
	 * How many nodes the last build wrote.
	 *
	 * @return int
	 *   The written count.
	 */
	public function writtenNodes(): int
	{
		return count($this->written);
	}

	/**
	 * How many subtrees the last build shared with the previous tree.
	 *
	 * @return int
	 *   The reused count.
	 */
	public function reusedSubtrees(): int
	{
		return $this->reused;
	}

	/**
	 * Reads a node.
	 *
	 * @param string $address
	 *   The node's address.
	 *
	 * @return MerkleNode
	 *   The node.
	 *
	 * @throws RuntimeException
	 *   When the node is absent or does not match its address.
	 */
	public function load(string $address): MerkleNode
	{
		$bytes = $this->provider->get(Hash::key($address, self::PREFIX));

		if (!Hash::equals(Hash::of($bytes), $address)) {
			throw new RuntimeException(
				sprintf('Tree node %s does not match its address', Hash::abbreviate($address)),
			);
		}

		/** @var array<string, mixed> $decoded */
		$decoded = json_decode($bytes, true) ?? [];

		return MerkleNode::fromArray($decoded);
	}

	/**
	 * Walks every leaf under a node.
	 *
	 * @param string $address
	 *   Address of the node to walk from.
	 * @param string $path
	 *   Path accumulated so far.
	 *
	 * @return array<string, array{frames: list<string>, size: int}>
	 *   Subject path keyed to its frame map and size.
	 *
	 * @throws RuntimeException
	 *   When a node is absent or corrupt.
	 */
	public function leaves(string $address, string $path = ''): array
	{
		$node = $this->load($address);

		if ($node->isLeaf()) {
			$key = $path === '' ? $node->name : $path;

			return [$key => ['frames' => $node->frames, 'size' => $node->size]];
		}

		$leaves = [];

		foreach ($node->children as $name => $child) {
			$childPath = $path === '' ? $name : $path . '/' . $name;
			$leaves = [...$leaves, ...$this->leaves($child, $childPath)];
		}

		return $leaves;
	}

	/**
	 * Splits subject paths into realm and name.
	 *
	 * @param array<string, array{frames: list<string>, size: int}> $subjects
	 *   Subject path keyed to its frame map and size.
	 *
	 * @return array<string, array<string, array{frames: list<string>, size: int}>>
	 *   Realm keyed to name keyed to the subject.
	 */
	private function group(array $subjects): array
	{
		$grouped = [];

		foreach ($subjects as $path => $subject) {
			$at = strpos($path, '/');
			$realm = $at === false ? $path : substr($path, 0, $at);
			$name = $at === false ? $path : substr($path, $at + 1);

			$grouped[$realm][$name] = $subject;
		}

		return $grouped;
	}

	/**
	 * Writes every realm branch and then the root.
	 *
	 * @param string $name
	 *   The root's name, which is always empty.
	 * @param array<string, array<string, array{frames: list<string>, size: int}>> $grouped
	 *   Realm keyed to name keyed to the subject.
	 *
	 * @return string
	 *   Address of the root node.
	 *
	 * @throws RuntimeException
	 *   When a node cannot be written.
	 */
	private function writeBranch(string $name, array $grouped): string
	{
		$children = [];

		foreach ($grouped as $realm => $subjects) {
			$leaves = [];

			foreach ($subjects as $subjectName => $subject) {
				$leaves[$subjectName] = $this->writeNode(
					MerkleNode::leaf($subjectName, $subject['frames'], $subject['size']),
				);
			}

			$children[$realm] = $this->writeBranchFromLeaves($realm, $leaves);
		}

		return $this->writeRoot($children);
	}

	/**
	 * Writes one realm branch over already-written leaves.
	 *
	 * @param string $realm
	 *   The realm name.
	 * @param array<string, string> $leaves
	 *   Leaf name keyed to leaf address.
	 *
	 * @return string
	 *   Address of the branch node.
	 *
	 * @throws RuntimeException
	 *   When the node cannot be written.
	 */
	private function writeBranchFromLeaves(string $realm, array $leaves): string
	{
		$size = 0;

		foreach ($leaves as $address) {
			$size += $this->sizeOf($address);
		}

		return $this->writeNode(MerkleNode::branch($realm, $leaves, $size, count($leaves)));
	}

	/**
	 * Writes the root over already-written realm branches.
	 *
	 * @param array<string, string> $children
	 *   Realm name keyed to branch address.
	 *
	 * @return string
	 *   Address of the root node.
	 *
	 * @throws RuntimeException
	 *   When the node cannot be written.
	 */
	private function writeRoot(array $children): string
	{
		$size = 0;
		$subjects = 0;

		foreach ($children as $address) {
			$node = $this->written[$address] ?? $this->load($address);
			$size += $node->size;
			$subjects += $node->subjects;
		}

		return $this->writeNode(MerkleNode::branch('', $children, $size, $subjects));
	}

	/**
	 * Writes a node unless the store already holds it.
	 *
	 * A node whose address is already present is byte-identical by construction, so the write is
	 * skipped and the subtree counted as reused.
	 *
	 * @param MerkleNode $node
	 *   The node.
	 *
	 * @return string
	 *   Its address.
	 *
	 * @throws RuntimeException
	 *   When the node cannot be written.
	 */
	private function writeNode(MerkleNode $node): string
	{
		$address = $node->address();
		$this->written[$address] = $node;

		$key = Hash::key($address, self::PREFIX);

		if ($this->provider->exists($key)) {
			$this->reused++;

			return $address;
		}

		$this->provider->put($key, (string) json_encode($node));

		return $address;
	}

	/**
	 * The decoded size a node covers.
	 *
	 * @param string $address
	 *   The node's address.
	 *
	 * @return int
	 *   Bytes.
	 *
	 * @throws RuntimeException
	 *   When the node is absent or corrupt.
	 */
	private function sizeOf(string $address): int
	{
		return ($this->written[$address] ?? $this->load($address))->size;
	}
}
