<?php

namespace Nixx\EasyWorkerman\Core;

use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresTransaction;
use Monolog\Level;
use Nixx\EasyWorkerman\Core\Arel\ArelInterface;

/**
 * @phpstan-type Params array<string, mixed>
 */
trait PostgresTrait {

	abstract public function connection(): PostgresConnectionPool|PostgresTransaction;

	/**
	 * Создает транзакцию у подключения
	 * @param callable(PgTransaction): void $func
	 * @throws \Throwable
	 */
	public function transaction(callable $func): void {
		$time = microtime(true);
		$transaction = $this->connection()->beginTransaction();
		$this->log($time, 'BEGIN', []);

		try {
			call_user_func($func, new PgTransaction($transaction));
			$time = microtime(true);
			$transaction->commit();
			$this->log($time, 'COMMIT', []);
		} catch (\Throwable $e) {
			$time = microtime(true);
			$transaction->rollback();
			$this->log($time, 'ROLLBACK', []);
			throw $e;
		}
	}

	/**
	 * @param non-empty-string $table
	 * @param Params           $params
	 * @param string[]         $columns
	 * @return array<non-empty-string, mixed>|null
	 */
	public function find_by(string $table, array $params = [], array $columns = ['*'], ?string $order = null): ?array {
		return $this->select($table, $params, $columns, 1, null, $order)->fetchRow();
	}

	/**
	 * @param non-empty-string $table
	 * @param Params           $params
	 * @return bool
	 */
	public function exists(string $table, array $params): bool {
		return !empty($this->find_by($table, $params, ['1']));
	}

	/**
	 * @param non-empty-string $table
	 * @param Params           $params
	 * @return int
	 */
	public function count(string $table, array $params = []): int {
		return $this->find_by($table, $params, ['count(*) AS c'])['c'];
	}

	/**
	 * @param non-empty-string $table
	 * @param Params           $params
	 * @param bool             $return
	 * @param string|null      $on_conflict
	 * @return array<non-empty-string, scalar|list<mixed>|null>|null
	 */
	public function insert(string $table, array $params, bool $return = false, ?string $on_conflict = null): ?array {
		$columns = implode(',', array_map(Postgres::get()->connection()->quoteIdentifier(...), array_keys($params)));
		$values = implode(',', array_map(fn($v) => ':' . $v, array_keys($params)));
		$result = $this->execute('INSERT INTO "' . $table . '" (' . $columns . ') VALUES (' . $values . ')' . ($on_conflict ? ' ON CONFLICT ' . $on_conflict : '') . ($return ? ' RETURNING *' : ''), $params, false);
		return $result->fetchRow();
	}

	/**
	 * @param non-empty-string $table
	 * @param Params           $params
	 * @return int
	 */
	public function delete(string $table, array $params): int {
		return $this->execute('DELETE FROM "' . $table . '" WHERE ' . $this->where($params), $params)->getRowCount();
	}

	/**
	 * @param non-empty-string $table
	 * @param Params           $update
	 * @param Params           $where
	 * @return int
	 */
	public function update(string $table, array $update, array $where): int {
		$time = microtime(true);

		$update_params = [];
		foreach( $update as $k => $v ) {
			$update_params['update_' . $k] = $v;
		}
		$sql = 'UPDATE "' . $table . '" SET ' . implode(', ', array_map(fn($k) => Postgres::get()
					->connection()
					->quoteIdentifier($k) . ' = :update_' . $k, array_keys($update))) . ($where ? ' WHERE ' . $this->where($where) : '');
		$params = array_merge($update_params, $where);

		//Подготавливаем параметры, т.к. дефолтный метод не может работать с массивом
		foreach( $where as $key => $value ) {
			if( is_array($value) ) {
				$sql = str_replace(':' . $key, Postgres::escapeLiteral($value), $sql);
				unset($where[$key]);
			}
		}

		$result = $this->connection()->execute($sql, $params);

		//Пишем в лог
		$this->log($time, $sql, $params);

		return $result->getRowCount();
	}

	/**
	 * @param non-empty-string $sql
	 * @param Params           $params
	 * @param bool             $prepare_array Нужно ли конвертировать массивы для использования в select запроса с column IN (...)
	 * @return PostgresResult
	 */
	public function execute(string $sql, array $params = [], bool $prepare_array = true): PostgresResult {
		$time = microtime(true);

		//Подготавливаем параметры, т.к. дефолтный метод не может работать с массивом
		if( $prepare_array ) {
			foreach( $params as $key => $value ) {
				if( is_array($value) ) {
					$sql = str_replace(':' . $key, Postgres::escapeLiteral($value), $sql);
					unset($params[$key]);
				}
			}
		}

		$result = $this->connection()->execute($sql, $params);

		//Пишем в лог
		$this->log($time, $sql, $params);

		return $result;
	}

	/**
	 * @param non-empty-string      $table
	 * @param Params                $params
	 * @param array                 $columns
	 * @param int|null              $limit
	 * @param int|null              $offset
	 * @param non-empty-string|null $order
	 * @param non-empty-string|null $group
	 * @return PostgresResult
	 */
	public function select(string $table, array $params, array $columns = ['*'], ?int $limit = null, ?int $offset = null, ?string $order = null, ?string $group = null): PostgresResult {
		//Строим условие
		$where = $this->where($params);

		$query = [
			'SELECT',
			implode(', ', $columns),
			'FROM',
			'"' . $table . '"',
		];

		if( $where ) {
			$query[] = 'WHERE ' . $where;
		}

		if( $group ) {
			$query[] = 'GROUP BY ' . $group;
		}

		if( $order ) {
			$query[] = 'ORDER BY ' . $order;
		}

		if( $limit ) {
			$query[] = 'LIMIT ' . $limit;
		}

		if( $offset ) {
			$query[] = 'OFFSET ' . $offset;
		}

		//Делаем запрос
		return $this->execute(implode(' ', $query), $params);
	}

	/**
	 * @param Model|non-empty-string $table
	 * @param string                 $column
	 * @param array                  $params
	 * @param int|null               $limit
	 * @param int|null               $offset
	 * @param string|null            $order
	 * @return array
	 */
	//@phpstan-ignore missingType.generics
	public function pluck(Model|string $table, string $column, array $params = [], ?int $limit = null, ?int $offset = null, ?string $order = null): array {
		$rows = [];
		foreach( $this->select($table, $params, [$column], $limit, $offset, $order) as $row ) {
			$rows[] = $row[$column];
		}
		return $rows;
	}

	/**
	 * @param Params $params
	 * @return string
	 */
	public function where(array $params): string {
		return implode(' AND ', array_map(function($k, $v) {
			if( $v instanceof ArelInterface ) {
				return $v->toSql($k);
			}
			if( is_null($v) ) {
				return $k . ' IS NULL';
			}
			return $k . ' ' . (is_array($v) ? 'IN (:' . $k . ')' : '= :' . $k);
		}, array_keys($params), array_values($params)));
	}

	/**
	 * @param non-empty-string $sql
	 * @return PostgresResult
	 */
	public function query(string $sql): PostgresResult {
		$time = microtime(true);

		$result = $this->connection()->query($sql);

		//Пишем в лог
		$this->log($time, $sql, []);

		return $result;
	}

	/**
	 * @param mixed $value
	 * @return float|int|string
	 */
	public static function escapeLiteral(mixed $value): float|int|string {
		if( is_bool($value) ) {
			return $value ? 'TRUE' : 'FALSE';
		} elseif( is_null($value) ) {
			return 'NULL';
		} elseif( is_array($value) ) {
			if( !$value ) {
				return 'NULL';
			}
			return implode(',', array_map(self::escapeLiteral(...), $value));
		} elseif( !is_int($value) && !is_float($value) ) {
			return Postgres::get()->connection()->quoteLiteral($value);
		}
		return $value;
	}

	/**
	 * @param float|string     $start_time
	 * @param non-empty-string $sql
	 * @param Params           $params
	 */
	private function log(float|string $start_time, string $sql, array $params): void {
		if( Logger::$logger->isHandling(Level::Debug) ) {
			foreach( $params as $key => $value ) {
				if( !($value instanceof ArelInterface) ) {
					$sql = str_replace(':' . $key, self::escapeLiteral($value), $sql);
				}
			}
			if( str_contains($sql, 'SELECT ') ) {
				$sql = "\033[1;34m{$sql}\033[0m";
			}
			if( str_contains($sql, 'UPDATE ') ) {
				$sql = "\033[1;33m{$sql}\033[0m";
			}
			if( str_contains($sql, 'INSERT ') ) {
				$sql = "\033[1;32m{$sql}\033[0m";
			}
			if( str_contains($sql, 'DELETE ') ) {
				$sql = "\033[1;31m{$sql}\033[0m";
			}
			Logger::$logger->debug("\033[1;36mSQL \033[1;35m(" . round((microtime(true) - $start_time) * 1000, 2) . "ms)\033[0m " . preg_replace('/\s\s+/', ' ', $sql));
		}
	}

}
