<?php

namespace Nixx\EasyWorkerman\Core\Arel;

use Nixx\EasyWorkerman\Core\Postgres;

final class Operator implements ArelInterface {

	public function __construct(protected string $operator, protected string|int|float $value) {}

	public function toSql(string $k): string {
		return $k . ' ' . $this->operator . ' ' . Postgres::escapeLiteral($this->value);
	}
}
