<?php

namespace Nixx\EasyWorkerman\Core\Arel;

use Nixx\EasyWorkerman\Core\Postgres;

final readonly class ArelSql implements ArelInterface {

	public function __construct(protected string $operator, protected string|int|float $value) {}

	public function toSql(): string {
		return $this->operator . ' ' . Postgres::escapeLiteral($this->value);
	}
}
