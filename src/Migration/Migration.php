<?php

namespace Nixx\EasyWorkerman\Migration;

use Nixx\EasyWorkerman\Core\Postgres;

abstract class Migration {

	public function driver(): Postgres {
		return Postgres::get();
	}

	abstract public function up(): void;

	abstract public function down(): void;

	protected function createTable(string $table, array $columns): void {
		$this->driver()->execute('CREATE TABLE ' . $table . ' (' . implode(', ', array_map(fn($k, $v) => $k . ' ' . $v, array_keys($columns), array_values($columns))) . ')');
	}

	protected function dropTable(string $table): void {
		$this->driver()->execute('DROP TABLE ' . $table);
	}
}
