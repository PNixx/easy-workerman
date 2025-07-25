<?php

namespace Nixx\EasyWorkerman\Core\Arel;

use JetBrains\PhpStorm\Deprecated;

#[Deprecated('Use new Arel\In() instead')]
final readonly class ArelSelect implements ArelInterface {

	public function __construct(protected string $sql) {}

	public function toSql(string $k): string {
		return $k . ' IN (' . $this->sql . ')';
	}
}
