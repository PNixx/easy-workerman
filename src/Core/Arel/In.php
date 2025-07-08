<?php

namespace Nixx\EasyWorkerman\Core\Arel;

final readonly class In implements ArelInterface {

	public function __construct(protected string $sql) {}

	public function toSql(string $k): string {
		return $k . ' IN (' . $this->sql . ')';
	}
}
