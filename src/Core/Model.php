<?php

namespace Nixx\EasyWorkerman\Core;

use Amp\Cache\CacheException;
use ArrayAccess;
use Nixx\EasyWorkerman\Core\Arel\ArelInterface;
use Nixx\EasyWorkerman\Error\NotFoundError;

/**
 * @template TData of array<string, scalar|list<mixed>|null>
 * @implements ArrayAccess<key-of<TData>, TData[key-of<TData>]>
 */
abstract class Model implements ArrayAccess {
	/** @var non-empty-string $table */
	public static string $table;
	public static string $primary_key = 'id';

	/**
	 * Данные которые были обновлены
	 * @var array<key-of<TData>, mixed>
	 */
	protected array $changed_data = [];

	/**
	 * Model constructor.
	 * @param array<key-of<TData>, mixed> $data
	 */
	final public function __construct(protected array $data) {}

	/**
	 * @return TData["id"]
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

	/**
	 * @param array-key $offset
	 * @param TData[key-of<TData>] $value
	 */
	public function offsetSet(mixed $offset, mixed $value): void {
		if( !$this->isNewRecord() && !array_key_exists($offset, $this->data) ) {
			throw new \InvalidArgumentException('Cannot modify non-existent property "' . $offset . '".');
		}
		$this->setField($offset, $value);
	}

	/**
	 * @template TKey of key-of<TData>
	 * @param TKey $offset
	 */
	public function offsetExists($offset): bool {
		return array_key_exists($offset, $this->data);
	}

	/**
	 * @template TKey of key-of<TData>
	 * @param TKey $offset
	 */
	public function offsetUnset($offset): void {
		throw new \InvalidArgumentException('Cannot modify non-existent property "' . $offset . '".');
	}

	/**
	 * @template TKey of key-of<TData>
	 * @param TKey $offset
	 * @return TData[TKey]
	 */
	public function offsetGet($offset): mixed {
		return $this->data[$offset];
	}

	/**
	 * @template TKey of key-of<TData>
	 * @param array<TKey, mixed> $data
	 */
	public function assignAttributes(array $data): void {
		foreach ($data as $key => $value) {
			$this[$key] = $value;
		}
	}

	/**
	 * Заменяет данные
	 * @param key-of<TData> $field
	 * @param mixed  $value
	 */
	protected function setField(string $field, mixed $value): void {
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
				Postgres::get()->update(static::$table, $this->changed_data, $this->getPrimaryKeyParams());
				$this->clearCache();
			}
			$this->changed_data = [];
		}
	}

	/**
	 * @return int
	 */
	public function delete(): int {
		$result = Postgres::get()->delete(static::$table, $this->getPrimaryKeyParams());
		$this->clearCache();
		return $result;
	}

	/**
	 * @return array
	 */
	private function getPrimaryKeyParams(): array {
		return [static::$primary_key => $this[static::$primary_key]];
	}

	/**
	 * @return void
	 */
	private function clearCache(): void {
		Redis::delete(static::getCacheKey($this->getPrimaryKeyParams()));
	}

	/**
	 * @param TData $data
	 * @return static<TData>
	 */
	protected static function build(array $data): static {
		/** @var static<TData> */
		return new static($data);
	}

	/**
	 * Создание записи в таблице
	 * @param array       $params
	 * @param string|null $on_conflict
	 * @return ($on_conflict is null ? static<TData> : static<TData>|null)
	 */
	public static function insert(array $params, ?string $on_conflict = null): ?static {
		/** @var TData|null $row */
		$row = Postgres::get()->insert(static::$table, $params, true, $on_conflict);
		if( $row !== null ) {
			return static::build($row);
		}
		return null;
	}

	/**
	 * @param int|string       $primary_key
	 * @param int<0, max>|null $ttl
	 * @return static<TData>
	 * @throws CacheException
	 * @throws NotFoundError
	 */
	public static function find(int|string $primary_key, ?int $ttl = null): static {
		return static::find_by([static::$primary_key => $primary_key], $ttl);
	}

	/**
	 * @param int|string       $primary_key
	 * @param int<0, max>|null $ttl
	 * @return static<TData>|null
	 * @throws CacheException
	 */
	public static function tryFind(int|string $primary_key, ?int $ttl = null): ?static {
		return static::tryFindBy([static::$primary_key => $primary_key], $ttl);
	}

	/**
	 * @param array                 $params
	 * @param int<0, max>|null      $cache Используем ли кеш для чтения / записи
	 * @param array                 $columns
	 * @param non-empty-string|null $order
	 * @return static<TData>
	 * @throws CacheException
	 * @throws NotFoundError
	 */
	public static function find_by(array $params, ?int $cache = null, array $columns = ['*'], ?string $order = null): static {
		return static::findBy($params, $cache, $columns, $order);
	}

	/**
	 * @param array                 $params
	 * @param int<0, max>|null      $cache Используем ли кеш для чтения / записи
	 * @param array                 $columns
	 * @param non-empty-string|null $order
	 * @return static<TData>
	 * @throws CacheException
	 * @throws NotFoundError
	 */
	public static function findBy(array $params, ?int $cache = null, array $columns = ['*'], ?string $order = null): static {
		$result = self::tryFindBy($params, $cache, $columns, $order);
		if( $result === null ) {
			$key = static::getCacheKey($params, $order);
			throw new NotFoundError($key . ' not found');
		}
		return $result;
	}

	/**
	 * @param array                 $params
	 * @param int<0, max>|null      $cache Используем ли кеш для чтения / записи
	 * @param array                 $columns
	 * @param non-empty-string|null $order
	 * @return static<TData>|null
	 * @throws CacheException
	 */
	public static function tryFindBy(array $params, ?int $cache = null, array $columns = ['*'], ?string $order = null): ?static {
		$func = fn() => Postgres::get()->find_by(static::$table, $params, $columns, $order);

		//Если можно искать в кеше
		$key = static::getCacheKey($params, $order);
		if( $cache ) {
			$result = Redis::cache($key, $func, $cache);
		} else {
			$result = $func();
		}

		if( $result !== null && $result !== '' ) {
			return static::build($result);
		}
		return null;
	}

	/**
	 * @param non-empty-string $where
	 * @param array            $params
	 * @param int|null         $cache Используем ли кеш для чтения / записи
	 * @return static
	 * @throws CacheException
	 * @throws NotFoundError
	 */
	public static function find_by_where(string $where, array $params, ?int $cache = null): static {
		$func = fn() => Postgres::get()->execute('SELECT * FROM ' . static::$table . ' WHERE ' . $where . ' LIMIT 1', $params)->fetchRow();

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

		return static::build($result);
	}

	/**
	 * @param array                 $params
	 * @param array                 $columns
	 * @param int|null              $limit
	 * @param int|null              $offset
	 * @param non-empty-string|null $order
	 * @param non-empty-string|null $group
	 * @return Collection<static>
	 */
	public static function select(array $params, array $columns = ['*'], ?int $limit = null, ?int $offset = null, ?string $order = null, ?string $group = null): Collection {
		$result = Postgres::get()->select(static::$table, $params, $columns, $limit, $offset, $order, $group);
		return new Collection($result, static::class);
	}

	/**
	 * @param non-empty-string      $column
	 * @param array                 $params
	 * @param int|null              $limit
	 * @param int|null              $offset
	 * @param non-empty-string|null $order
	 * @param non-empty-string|null $group
	 * @return array
	 */
	public static function pluck(string $column, array $params = [], ?int $limit = null, ?int $offset = null, ?string $order = null, ?string $group = null): array {
		return Postgres::get()->pluck(static::$table, $column, $params, $limit, $offset, $order, $group);
	}

	/**
	 * @param array    $params
	 * @param int|null $ttl
	 * @return bool
	 * @throws CacheException
	 */
	public static function exists(array $params, ?int $ttl = null): bool {
		$func = fn() => Postgres::get()->exists(static::$table, $params);
		if( $ttl !== null ) {
			return Redis::cache('exists:' . static::getCacheKey($params), $func, $ttl, false, true);
		}
		return $func();
	}

	/**
	 * Возвращает имя текущего класса без namespace
	 * @return string
	 */
	final public static function getClassName(): string {
		return (new \ReflectionClass(static::class))->getShortName();
	}

	/**
	 * @param array       $params
	 * @param string|null $order
	 * @return string
	 */
	final public static function getCacheKey(array $params, ?string $order = null): string {
		$data = [
			static::getClassName(),
			...array_map(function($k, $v) {
				if( $v instanceof ArelInterface ) {
					$v = md5($v->toSql($k));
				}
				return $k . ':' . (is_array($v) ? implode(',', $v) : $v);
			}, array_keys($params), $params),
		];

		if( $order !== null ) {
			$data[] = 'order:' . $order;
		}

		return implode(':', $data);
	}
}
