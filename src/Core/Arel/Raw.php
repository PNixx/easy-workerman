<?php

namespace Nixx\EasyWorkerman\Core\Arel;

class Raw implements ArelInterface {

	public function __construct(protected string $sql) {}

	public function toSql(string $k): string {
		return $this->sql;
	}
}
