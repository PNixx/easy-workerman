<?php

namespace Nixx\EasyWorkerman\Core\Arel;


final readonly class NotNull implements ArelInterface {

	public function toSql(string $k): string {
		return $k . ' IS NOT NULL';
	}
}
