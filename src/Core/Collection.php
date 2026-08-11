<?php

namespace Nixx\EasyWorkerman\Core;

use Amp\Postgres\PostgresResult;

/**
 * @template-covariant T
 * @implements \IteratorAggregate<int, T>
 */
final readonly class Collection implements \IteratorAggregate, \JsonSerializable, \Countable {

	/**
	 * @param PostgresResult  $result
	 * @param class-string<T> $class
	 */
	public function __construct(protected PostgresResult $result, protected string $class) {}

	/**
	 * @return int<0, max>
	 */
	public function count(): int {
		$count = $this->result->getRowCount();
		if ($count > 0) {
			return $count;
		}
		return 0;
	}

	public function jsonSerialize(): array {
		return array_map(fn(array $v) => new $this->class($v), iterator_to_array($this->result));
	}

	/**
	 * @return \Iterator<int, T>
	 */
	public function getIterator(): \Iterator {
		foreach( $this->result as $row ) {
			yield new $this->class($row);
		}
	}
}
