<?php

namespace Nixx\EasyWorkerman\Core\Arel;

final readonly class ArelSelect implements ArelInterface {

	public function __construct(protected string $sql) {}

	public function toSql(): string {
		return ' IN (' . $this->sql . ')';
	}
}
