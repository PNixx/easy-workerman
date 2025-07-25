<?php

namespace Nixx\EasyWorkerman\Core\Arel;

use JetBrains\PhpStorm\Deprecated;
use Nixx\EasyWorkerman\Core\Postgres;

#[Deprecated('Use new Arel\Operator() instead')]
final readonly class ArelSql implements ArelInterface {

	public function __construct(protected string $operator, protected string|int|float $value) {}

	public function toSql(string $k): string {
		return $k . ' ' . $this->operator . ' ' . Postgres::escapeLiteral($this->value);
	}
}
