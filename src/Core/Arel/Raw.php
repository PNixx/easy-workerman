<?php

namespace Nixx\EasyWorkerman\Core\Arel;

final readonly class Raw implements ArelInterface, \Stringable {

	public function __construct(protected string $sql, protected string $value = '') {}

	public function toSql(string $k): string {
		return $this->sql;
	}

	public function __toString(): string {
		return $this->value;
	}
}
