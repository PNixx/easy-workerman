<?php

namespace Nixx\EasyWorkerman\Test;

use Amp\Postgres\DefaultPostgresConnector;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresTransaction;
use Nixx\EasyWorkerman\Core\Arel;
use Nixx\EasyWorkerman\Core\PostgresTrait;
use PHPUnit\Framework\TestCase;

class PostgresTestMock {
	use PostgresTrait;

	public function connection(): PostgresTransaction|PostgresConnectionPool {
		return new PostgresConnectionPool(PostgresConfig::fromString(''), connector: new DefaultPostgresConnector());
	}
}

class QueryBuilderTest extends TestCase {

	protected PostgresTestMock $postgres;

	protected function setUp(): void {
		$this->postgres = $this->getMockBuilder(PostgresTestMock::class)->onlyMethods(['execute'])->getMock();
	}

	public function testSelect() {
		$this->postgres->expects($this->once())->method('execute')->with('SELECT * FROM "events" WHERE id = :id', ['id' => 1]);
		$this->postgres->select('events', ['id' => 1]);
	}

	public function testSelectOperator() {
		$this->postgres->expects($this->once())->method('execute')->with('SELECT * FROM "events" WHERE id < 1', ['id' => new Arel\Operator('<', 1)]);
		$this->postgres->select('events', ['id' => new Arel\Operator('<', 1)]);
	}

	public function testSelectRaw() {
		$args = ['query' => new Arel\Raw('strpos(lower(c), :query) > 0')];
		$this->postgres->expects($this->once())->method('execute')->with('SELECT * FROM "events" WHERE strpos(lower(c), :query) > 0', $args);
		$this->postgres->select('events', $args);
	}

	public function testSelectNotNull() {
		$args = ['query' => new Arel\NotNull()];
		$this->postgres->expects($this->once())->method('execute')->with('SELECT * FROM "events" WHERE query IS NOT NULL', $args);
		$this->postgres->select('events', $args);
	}

	public function testSelectBetween() {
		$args = ['query' => new Arel\Between(1, 5)];
		$this->postgres->expects($this->once())->method('execute')->with('SELECT * FROM "events" WHERE query BETWEEN 1 AND 5', $args);
		$this->postgres->select('events', $args);
	}
}
