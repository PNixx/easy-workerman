<?php

namespace Nixx\EasyWorkerman\Core;

use Amp\Postgres\DefaultPostgresConnector;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresQueryError;
use Amp\Postgres\PostgresTransaction;

final class Postgres {
	use PostgresTrait;

	private readonly PostgresConnectionPool $connection;
	private static Postgres $instance;

	/**
	 * Хранилище транзакции для выполнения всех запросов в ней.
	 * Используется только для тестов
	 * @var PostgresTransaction|null
	 */
	protected ?PostgresTransaction $transaction = null;

	/**
	 * @return Postgres
	 */
	public static function get(): Postgres {
		return Postgres::$instance;
	}

	/**
	 * @param array  $config
	 * @param string $name
	 */
	public static function init(array $config, string $name): void {
		self::$instance = new Postgres($config, $name);
	}

	/**
	 * Connection constructor.
	 * @param array  $config
	 * @param string $name
	 */
	public function __construct(array $config, string $name) {
		//Подключение к базе
		$this->connection = new PostgresConnectionPool(new PostgresConfig($config['host'], $config['port'], $config['username'], $config['password'], $config['database'], $name), $config['pool'], connector: new DefaultPostgresConnector());
	}

	/**
	 * @return PostgresTransaction|PostgresConnectionPool
	 */
	public function connection(): PostgresTransaction|PostgresConnectionPool {
		return $this->transaction ?: $this->connection;
	}

	/**
	 * Закрывает подключение
	 */
	public function close(): void {
		$this->connection->close();
	}

	/**
	 * Создает транзакцию у подключения. После вызова все запросы идут через транзакцию, для завершения вызывать: commit() или rollback()
	 */
	public function begin_transaction(): void {
		if( $this->transaction ) {
			throw new PostgresQueryError('Transaction already active', [], '');
		}
		$time = microtime(true);
		$this->transaction = $this->connection->beginTransaction();
		$this->log($time, 'BEGIN', []);
	}

	/**
	 * Применяет ранее открытую транзакцию
	 */
	public function commit(): void {
		if( !$this->transaction ) {
			throw new PostgresQueryError('Transaction not active', [], '');
		}

		$time = microtime(true);
		$this->transaction->commit();
		$this->transaction = null;
		$this->log($time, 'COMMIT', []);
	}

	/**
	 * Отменяет транзакцию
	 */
	public function rollback(): void {
		if( !$this->transaction ) {
			throw new PostgresQueryError('Transaction not active', [], '');
		}

		$time = microtime(true);
		$this->transaction->rollback();
		$this->transaction = null;
		$this->log($time, 'ROLLBACK', []);
	}
}
