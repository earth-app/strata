<?php

declare(strict_types=1);

namespace Drupal\strata\Tree;

use Drupal\strata\Cas\Hash;
use InvalidArgumentException;
use JsonSerializable;

/**
 * One node of the tree that describes the site at an instant.
 *
 * A node is either a leaf naming one subject and the frame map that reconstructs it, or an interior
 * node naming child nodes by their own addresses. A node's address is the digest of its serialized
 * children, so an unchanged subtree keeps its address and is never rewritten.
 *
 * That property is what makes a base anchor affordable. A full copy of the site per hour would cost
 * roughly a gigabyte an hour on a 50,000-user site. Measured against real churn, 3,454 writes an
 * hour over 262,000 subjects touch 3,429 distinct subjects, so 1.31% of the tree changes per hour
 * and only that fraction of nodes needs writing. Everything else is shared with the previous tree
 * by address.
 *
 * @see TreeBuilder
 * @see Commit
 */
final class MerkleNode implements JsonSerializable
{
	/**
	 * A node naming one subject.
	 */
	public const KIND_LEAF = 'leaf';

	/**
	 * A node naming child nodes.
	 */
	public const KIND_BRANCH = 'branch';

	/**
	 * Constructs a node.
	 *
	 * @param string $kind
	 *   MerkleNode::KIND_LEAF or MerkleNode::KIND_BRANCH.
	 * @param string $name
	 *   The path segment this node sits under in its parent. Empty for a root.
	 * @param list<string> $frames
	 *   Frame content addresses that reconstruct the subject, for a leaf. Empty for a branch.
	 * @param array<string, string> $children
	 *   Child name keyed to child node address, for a branch. Empty for a leaf.
	 * @param int $size
	 *   Decoded size of the subject in bytes, for a leaf; the sum of the subtree for a branch.
	 * @param int $subjects
	 *   Subjects under this node; 1 for a leaf.
	 *
	 * @throws InvalidArgumentException
	 *   When the kind, frames and children do not describe a coherent node, or an address is not a
	 *   valid digest.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $name = '',
		public readonly array $frames = [],
		public readonly array $children = [],
		public readonly int $size = 0,
		public readonly int $subjects = 0,
	) {
		if ($kind !== self::KIND_LEAF && $kind !== self::KIND_BRANCH) {
			throw new InvalidArgumentException(sprintf('Unknown merkle node kind "%s"', $kind));
		}
		if ($size < 0 || $subjects < 0) {
			throw new InvalidArgumentException(
				'A merkle node size and subject count cannot be negative',
			);
		}

		if ($kind === self::KIND_LEAF) {
			if ($children !== []) {
				throw new InvalidArgumentException('A leaf node cannot have children');
			}
			foreach ($frames as $frame) {
				if (!Hash::isValid($frame)) {
					throw new InvalidArgumentException('A leaf node frame must be a valid digest');
				}
			}

			return;
		}

		if ($frames !== []) {
			throw new InvalidArgumentException('A branch node cannot name frames directly');
		}
		foreach ($children as $childName => $address) {
			if ($childName === '') {
				throw new InvalidArgumentException('A child node must be named');
			}
			if (!Hash::isValid($address)) {
				throw new InvalidArgumentException(
					sprintf('Child "%s" must be addressed by a valid digest', $childName),
				);
			}
		}
	}

	/**
	 * Builds a leaf.
	 *
	 * @param string $name
	 *   The subject's path segment.
	 * @param list<string> $frames
	 *   Frame content addresses that reconstruct it.
	 * @param int $size
	 *   Decoded size in bytes.
	 *
	 * @return self
	 *   The leaf.
	 */
	public static function leaf(string $name, array $frames, int $size): self
	{
		return new self(self::KIND_LEAF, $name, $frames, [], $size, 1);
	}

	/**
	 * Builds a branch.
	 *
	 * @param string $name
	 *   The branch's path segment, or an empty string for a root.
	 * @param array<string, string> $children
	 *   Child name keyed to child node address.
	 * @param int $size
	 *   Total decoded size of the subtree.
	 * @param int $subjects
	 *   Subjects under the branch.
	 *
	 * @return self
	 *   The branch.
	 */
	public static function branch(string $name, array $children, int $size, int $subjects): self
	{
		return new self(self::KIND_BRANCH, $name, [], $children, $size, $subjects);
	}

	/**
	 * Whether this node names a subject.
	 *
	 * @return bool
	 *   TRUE for a leaf.
	 */
	public function isLeaf(): bool
	{
		return $this->kind === self::KIND_LEAF;
	}

	/**
	 * This node's content address.
	 *
	 * Derived from the serialized node, so two nodes with the same content share an address and are
	 * stored once. Children are sorted by name first, so an address does not depend on the order a
	 * builder happened to add them in.
	 *
	 * @return string
	 *   A 64-character lowercase hex digest.
	 */
	public function address(): string
	{
		return Hash::of((string) json_encode($this->canonical()));
	}

	/**
	 * The node in a form whose serialization is stable.
	 *
	 * @return array<string, mixed>
	 *   The node with its children in name order.
	 */
	private function canonical(): array
	{
		$children = $this->children;
		ksort($children, SORT_STRING);

		return [
			'kind' => $this->kind,
			'name' => $this->name,
			'frames' => $this->frames,
			'children' => $children,
			'size' => $this->size,
			'subjects' => $this->subjects,
		];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The node as a plain array, with children in name order.
	 */
	public function jsonSerialize(): array
	{
		return $this->canonical();
	}

	/**
	 * Rebuilds a node from its serialized form.
	 *
	 * @param array<string, mixed> $data
	 *   The array produced by MerkleNode::jsonSerialize().
	 *
	 * @return self
	 *   The node.
	 *
	 * @throws InvalidArgumentException
	 *   When the kind is missing or the node is incoherent.
	 */
	public static function fromArray(array $data): self
	{
		if (!array_key_exists('kind', $data)) {
			throw new InvalidArgumentException('A merkle node is missing "kind"');
		}

		/** @var list<string> $frames */
		$frames = $data['frames'] ?? [];
		/** @var array<string, string> $children */
		$children = $data['children'] ?? [];

		return new self(
			(string) $data['kind'],
			(string) ($data['name'] ?? ''),
			$frames,
			$children,
			(int) ($data['size'] ?? 0),
			(int) ($data['subjects'] ?? 0),
		);
	}
}
