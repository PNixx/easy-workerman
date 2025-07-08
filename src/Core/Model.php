<?php

namespace Nixx\EasyWorkerman\Core;

use Amp\Cache\CacheException;
use JetBrains\PhpStorm\Immutable;
use Nixx\EasyWorkerman\Core\Arel\ArelInterface;
use Nixx\EasyWorkerman\Error\NotFoundError;

abstract class Model implements \ArrayAccess {
	public static string $table;
	public static string $primary_key = 'id';

	/**
	 * @var array
	 */
	protected array $data;

	/**
	 * Данные которые были обновлены
	 * @var array
	 */
	#[Immutable(Immutable::PROTECTED_WRITE_SCOPE)]
	public array $changed_data = [];

	/**
	 * Model constructor.
	 * @param array $data
	 */
	public function __construct(array $data) {
		$this->data = $data;
	}

	/**
	 * @return mixed
	 */
	final public function id(): mixed {
		return $this->data['_id'] ?? $this->data['id'] ?? null;
	}

	/**
	 * @return array
	 * @deprecated Use $model[key]
	 */
	public function getData(): array {
		return $this->data;
	}

	public function offsetSet(mixed $offset, mixed $value): void {
		if( !array_key_exists($offset, $this->data) ) {
			throw new \InvalidArgumentException('Cannot modify non-existent property "' . $offset . '".');
		}
		$this->setField($offset, $value);
	}

	public function offsetExists($offset): bool {
		return array_key_exists($offset, $this->data);
	}

	public function offsetUnset($offset): void {
		throw new \InvalidArgumentException('Cannot modify non-existent property "' . $offset . '".');
	}

	public function offsetGet($offset): mixed {
		return $this->data[$offset];
	}

	/**
	 * Заменяет данные
	 * @param string $field
	 * @param        $value
	 */
	public function setField(string $field, $value): void {
		if( !array_key_exists($field, $this->data) || $this->data[$field] !== $value ) {
			$this->data[$field] = $value;
			$this->changed_data[$field] = $value;
		}
	}

	/**
	 * @return bool
	 */
	public function isChanged(): bool {
		return $this->isNewRecord() || count($this->changed_data) > 0;
	}

	/**
	 * @return bool
	 */
	public function isNewRecord(): bool {
		//Если в changed_data нет первичного ключа, значит запись существует
		return empty($this->data[static::$primary_key]);
	}

	/**
	 * @return void
	 */
	public function save(): void {
		if( $this->isChanged() ) {
			if( $this->isNewRecord() ) {
				$data = Postgres::get()->insert(static::$table, $this->data, true);
				$this->data = $data;
			} else {
				Postgres::get()->update(static::$table, $this->changed_data, [static::$primary_key => $this->getData()[static::$primary_key]]);
			}
			$this->changed_data = [];
		}
	}

	/**
	 * @return array
	 */
	public function delete(): array {
		return Postgres::get()->delete(static::$table, [static::$primary_key => $this->data[static::$primary_key]]);
	}

	/**
	 * Создание записи в таблице
	 * @param array       $params
	 * @param string|null $on_conflict
	 * @return static|null
	 */
	public static function insert(array $params, ?string $on_conflict = null): ?static {
		$row = Postgres::get()->insert(static::$table, $params, true, $on_conflict);
		if( $row ) {
			return new static($row);
		}
		return null;
	}

	/**
	 * @param int|string $primary_key
	 * @param int|null   $ttl
	 * @return static
	 * @throws CacheException
	 * @throws NotFoundError
	 */
	public static function find(int|string $primary_key, ?int $ttl = null): static {
		return static::find_by([static::$primary_key => $primary_key], $ttl);
	}

	/**
	 * @param array    $params
	 * @param int|null $cache Используем ли кеш для чтения / записи
	 * @param array    $columns
	 * @return static
	 * @throws NotFoundError
	 * @throws CacheException
	 */
	public static function find_by(array $params, ?int $cache = null, array $columns = ['*']): static {
		$func = fn() => Postgres::get()->find_by(static::$table, $params, $columns);

		//Если можно искать в кеше
		$key = static::getCacheKey($params);
		if( $cache ) {
			$result = Redis::cache($key, $func, $cache);
		} else {
			$result = $func();
		}
		//Ищем строку
		if( empty($result) ) {
			throw new NotFoundError($key . ' not found');
		}

		return new static($result);
	}

	/**
	 * @param string   $where
	 * @param array    $params
	 * @param int|null $cache Используем ли кеш для чтения / записи
	 * @return static
	 * @throws CacheException
	 * @throws NotFoundError
	 */
	public static function find_by_where(string $where, array $params, ?int $cache = null): static {
		if( empty($where) ) {
			throw new \Exception('Where clause can not be blank');
		}
		$func = fn() => current(Postgres::get()->execute('SELECT * FROM ' . static::$table . ' WHERE ' . $where . ' LIMIT 1', $params));

		//Если можно искать в кеше
		$key = static::getCacheKey($params);
		if( $cache ) {
			$result = Redis::cache($key . ':' . md5($where), $func, $cache);
		} else {
			$result = $func();
		}
		//Ищем строку
		if( empty($result) ) {
			throw new NotFoundError($key . ' not found');
		}

		return new static($result);
	}

	/**
	 * @param array       $params
	 * @param array       $columns
	 * @param int|null    $limit
	 * @param int|null    $offset
	 * @param string|null $order
	 * @return static[]
	 */
	public static function select(array $params, array $columns = ['*'], ?int $limit = null, ?int $offset = null, ?string $order = null): array {
		return array_map(fn($v) => new static($v), Postgres::get()->select(static::$table, $params, $columns, $limit, $offset, $order));
	}

	/**
	 * @param array $params
	 * @return bool
	 */
	public static function exists(array $params): bool {
		return Postgres::get()->exists(static::$table, $params);
	}

	/**
	 * Возвращает имя текущего класса без namespace
	 * @return string
	 */
	final public static function getClassName(): string {
		return (new \ReflectionClass(static::class))->getShortName();
	}

	/**
	 * @param array $params
	 * @return string
	 */
	final public static function getCacheKey(array $params): string {
		return implode(':', [
			static::getClassName(),
			...array_map(function($k, $v) {
				if( $v instanceof ArelInterface ) {
					$v = md5($v->toSql($k));
				}
				return $k . ':' . (is_array($v) ? implode(',', $v) : $v);
			}, array_keys($params), $params),
		]);
	}

	/**
	 * @param int|string $primary_key
	 */
	final public static function clearFindCache(int|string $primary_key): void {
		Redis::delete(static::getCacheKey([static::$primary_key => $primary_key]));
	}
}
