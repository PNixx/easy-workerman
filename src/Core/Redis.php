<?php

namespace Nixx\EasyWorkerman\Core;

use Amp\Cache\CacheException;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisSubscriber;
use Monolog\Level;
use function Amp\Redis\createRedisClient;
use function Amp\Redis\createRedisConnector;

final class Redis {

	protected RedisClient $connection;
	protected static Redis $instance;
	protected array $config;
	protected static RedisSubscriber $subscriber;

	/**
	 * @return RedisClient|null
	 */
	public static function client(): ?RedisClient {
		if( isset(Redis::$instance) ) {
			return Redis::$instance->connection;
		}
		return null;
	}

	/**
	 * @return RedisSubscriber|null
	 */
	public static function subscriber(): ?RedisSubscriber {
		if( !isset(self::$subscriber) ) {
			self::$subscriber = new RedisSubscriber(createRedisConnector('tcp://' . self::$instance->config['url']));
		}
		return self::$subscriber;
	}

	/**
	 * Connection constructor.
	 * @param array $config
	 */
	public function __construct(array $config) {
		self::$instance = $this;
		$this->config = $config;
		$this->connect();
	}

	/**
	 * Подключаемся к редису
	 */
	public static function connect(): void {
		if( isset(self::$instance->connection) ) {
			self::$instance->connection->quit();
			unset(self::$instance->connection);
		}
		self::$instance->connection = createRedisClient('tcp://' . self::$instance->config['url']);
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $ttl
	 */
	public static function set(string $key, mixed $value, int $ttl = 60): void {
		Redis::client()?->set($key, json_encode($value, JSON_UNESCAPED_UNICODE));
		Redis::client()?->expireIn($key, $ttl);
	}

	/**
	 * @param string $key
	 * @return string|null
	 */
	public static function get(string $key): ?string {
		return Redis::client()?->get($key);
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $ttl
	 */
	public static function add(string $key, mixed $value, int $ttl = 60): void {
		Redis::client()?->setWithoutOverwrite($key, $value);
		Redis::client()?->expireIn($key, $ttl);
	}

	/**
	 * @param string $key
	 * @return int|null
	 */
	public static function delete(string $key): ?int {
		return Redis::client()?->delete($key);
	}

	/**
	 * @param string   $key
	 * @param int|null $ttl
	 * @return void
	 */
	public static function increment(string $key, ?int $ttl = null): void {
		Redis::client()?->increment($key);
		if( $ttl ) {
			Redis::client()?->expireIn($key, $ttl);
		}
	}

	/**
	 * Запускает callback, если блокировки нет
	 * @param string   $key
	 * @param callable $callback
	 * @param int      $ttl
	 * @param bool     $need_unlock
	 */
	public static function lock(string $key, callable $callback, int $ttl = 300, bool $need_unlock = true): void {
		$id = uniqid();
		Redis::client()->setWithoutOverwrite($key, $id);
		if( Redis::client()->get($key) == $id ) {
			try {
				Redis::client()->expireIn($key, $ttl);
				call_user_func($callback);
			} finally {
				if( $need_unlock ) {
					Redis::client()->delete($key);
				}
			}
		}
	}

	/**
	 * Чтение / сохранение из кеша
	 * @param string        $key
	 * @param callable|null $func
	 * @param int           $ttl
	 * @param bool          $renew
	 * @param bool          $save_null
	 * @return mixed
	 * @throws CacheException
	 */
	public static function cache(string $key, ?callable $func = null, int $ttl = 60, bool $renew = false, bool $save_null = false): mixed {
		$time = microtime(true);
		$key = preg_replace(['/[^[:print:]]/', '/\s+/'], ['', '_'], $key);
		$result = Redis::client()?->get($key);
		if( $result ) {
			if( $renew ) {
				Redis::client()->expireIn($key, $ttl);
			}
			if( Logger::$logger->isHandling(Level::Debug) ) {
				Logger::$logger->debug("\033[1;36mCACHE \033[1;35m(" . round((microtime(true) - $time) * 1000, 2) . "ms)\033[0m \e[1;32m" . $key . "\e[0m");
			}

			return json_decode($result, true);
		}

		//Если колбека не объявлено, уходим
		if( $func === null ) {
			return null;
		}

		//Выполняем функцию
		$result = $func();

		//Сохраняем в кеш
		if( $result === null && $save_null || $result !== null ) {
			Redis::set($key, $result, $ttl);
		}

		return $result;
	}
}
