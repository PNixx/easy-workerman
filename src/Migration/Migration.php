<?php

namespace Nixx\EasyWorkerman\Migration;

use Nixx\EasyWorkerman\Core\PgTransaction;
use Nixx\EasyWorkerman\Core\Postgres;

abstract class Migration {

	public function __construct(protected readonly ?PgTransaction $transaction = null) {}

	public function driver(): Postgres|PgTransaction {
		return $this->transaction ?: Postgres::get();
	}

	abstract public function up(): void;

	abstract public function down(): void;

	protected function createTable(string $table, array $columns, ?array $primary_keys = null): void {
		$rows = array_map(fn($k, $v) => $k . ' ' . $v, array_keys($columns), array_values($columns));
		if( $primary_keys ) {
			$rows[] = 'PRIMARY KEY (' . implode(', ', $primary_keys) . ')';
		}
		$this->driver()->execute('CREATE TABLE ' . $table . ' (' . implode(', ', $rows) . ')');
	}

	protected function dropTable(string $table): void {
		$this->driver()->execute('DROP TABLE ' . $table);
	}
}
