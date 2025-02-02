<?php

namespace Nixx\EasyWorkerman\Core\Arel;


final readonly class NotNull implements ArelInterface {

	public function toSql(): string {
		return 'IS NOT NULL';
	}
}
