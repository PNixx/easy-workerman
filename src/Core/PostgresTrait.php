<?php

namespace Nixx\EasyWorkerman\Core;

use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresTransaction;
use Monolog\Level;
use Nixx\EasyWorkerman\Core\Arel\ArelInterface;

trait PostgresTrait {

	abstract public function connection(): PostgresConnectionPool|PostgresTransaction;

	/**
	 * Создает транзакцию у подключения
	 * @param callable $func
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
	 * @param string   $table
	 * @param array    $params
	 * @param string[] $columns
	 * @return array|null
	 */
	public function find_by(string $table, array $params = [], array $columns = ['*']): ?array {
		return current($this->select($table, $params, $columns, 1)) ?: null;
	}

	/**
	 * @param string $table
	 * @param array  $params
	 * @return bool
	 */
	public function exists(string $table, array $params): bool {
		return !empty($this->find_by($table, $params, ['1']));
	}

	/**
	 * @param string $table
	 * @param array  $params
	 * @return int
	 */
	public function count(string $table, array $params = []): int {
		return $this->find_by($table, $params, ['count(*) AS c'])['c'];
	}

	/**
	 * @param string      $table
	 * @param array       $params
	 * @param bool        $return
	 * @param string|null $on_conflict
	 * @return array|null
	 */
	public function insert(string $table, array $params, bool $return = false, ?string $on_conflict = null): ?array {
		$result = $this->execute('INSERT INTO "' . $table . '" (' . implode(',', array_map(Postgres::get()->connection()->quoteIdentifier(...), array_keys($params))) . ') VALUES (' . implode(',', array_map(fn($v) => ':' . $v, array_keys($params))) . ')' . ($on_conflict ? ' ON CONFLICT ' . $on_conflict : '') . ($return ? ' RETURNING *' : ''), $params, false);
		if( $result ) {
			return $result[0] ?? null;
		}
		return null;
	}

	/**
	 * @param string $table
	 * @param array  $params
	 * @return array
	 */
	public function delete(string $table, array $params): array {
		return $this->execute('DELETE FROM "' . $table . '" WHERE ' . $this->where($params), $params);
	}

	/**
	 * @param string $table
	 * @param array  $update
	 * @param array  $where
	 * @return int
	 */
	public function update(string $table, array $update, array $where): int {
		$time = microtime(true);

		$update_params = [];
		foreach( $update as $k => $v ) {
			$update_params['update_' . $k] = $v;
		}
		$sql = 'UPDATE "' . $table . '" SET ' . implode(', ', array_map(fn($k) => Postgres::get()->connection()->quoteIdentifier($k) . ' = :update_' . $k, array_keys($update))) . ($where ? ' WHERE ' . $this->where($where) : '');
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
	 * @param string $sql
	 * @param array  $params
	 * @param bool   $prepare_array Нужно ли конвертировать массивы для использования в select запроса с column IN (...)
	 * @return array|null
	 */
	public function execute(string $sql, array $params = [], bool $prepare_array = true): ?array {
		$time = microtime(true);

		//todo проверить
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

		return iterator_to_array($result);
	}

	/**
	 * @param string|Model $table
	 * @param array        $params
	 * @param array        $columns
	 * @param int|null     $limit
	 * @param int|null     $offset
	 * @param string|null  $order
	 * @return array|null
	 */
	public function select(Model|string $table, array $params, array $columns = ['*'], ?int $limit = null, ?int $offset = null, ?string $order = null): ?array {
		//Строим условие
		$where = $this->where($params);

		//Делаем запрос
		return $this->execute('SELECT ' . implode(', ', $columns) . ' FROM "' . ($table instanceof Model ? $table::$table : $table) . '"' . ($where ? ' WHERE ' . $where : '') . ($order ? ' ORDER BY ' . $order : '') . ($limit ? ' LIMIT ' . $limit : '') . ($offset ? ' OFFSET ' . $offset : ''), $params);
	}

	/**
	 * @param array $params
	 * @return string
	 */
	public function where(array $params): string {
		return implode(' AND ', array_map(function($k, $v) {
			if( $v instanceof ArelInterface ) {
				return $k . ' ' . $v->toSql();
			}
			if( is_null($v) ) {
				return $k . ' IS NULL';
			}
			return $k . ' ' . (is_array($v) ? 'IN (:' . $k . ')' : '= :' . $k);
		}, array_keys($params), array_values($params)));
	}

	/**
	 * @param string $sql
	 * @return array|null
	 */
	public function query(string $sql): ?array {
		$time = microtime(true);

		$result = $this->connection()->query($sql);

		//Пишем в лог
		$this->log($time, $sql, []);

		return iterator_to_array($result);
	}

	/**
	 * @param $value
	 * @return float|int|string
	 */
	public static function escapeLiteral($value): float|int|string {
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

	private function log($start_time, $sql, $params): void {
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
