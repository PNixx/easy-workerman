<?php

namespace Nixx\EasyWorkerman\Core;

use Amp\Postgres\PostgresTransaction;

class PgTransaction {
	use PostgresTrait;

	public function __construct(protected readonly PostgresTransaction $connection) {}

	public function connection(): PostgresTransaction {
		return $this->connection;
	}
}
