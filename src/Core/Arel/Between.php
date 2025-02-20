<?php

namespace Nixx\EasyWorkerman\Core\Arel;

use Nixx\EasyWorkerman\Core\Postgres;

final readonly class Between implements ArelInterface {

	public function __construct(protected string|int|float $from, protected string|int|float $to) {}

	public function toSql(): string {
		return 'BETWEEN ' . Postgres::escapeLiteral($this->from) . ' AND ' . Postgres::escapeLiteral($this->to);
	}
}
