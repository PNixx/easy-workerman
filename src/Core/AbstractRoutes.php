<?php

namespace Nixx\EasyWorkerman\Core;

use FastRoute\ConfigureRoutes;
use FastRoute\Dispatcher;
use FastRoute\FastRoute;
use Nixx\EasyWorkerman\Error\NotFoundError;

abstract class AbstractRoutes {

	private static Dispatcher $dispatcher;

	/**
	 * @param string $method
	 * @param string $path
	 * @return array
	 * @throws NotFoundError
	 */
	public static function route(string $method, string $path): array {

		//Если еще не было инициализации
		if( !isset(self::$dispatcher) ) {
			self::$dispatcher = FastRoute::recommendedSettings(fn(ConfigureRoutes $r) => (new static())->dispatch($r), 'route')->disableCache()->dispatcher('');
		}

		//Определяем пути
		$routeInfo = self::$dispatcher->dispatch($method, $path);
		if( $routeInfo[0] === Dispatcher::FOUND ) {
			return $routeInfo[1] + ['vars' => $routeInfo[2]];
		}
		throw new NotFoundError('Page not found');
	}

	/**
	 * @param ConfigureRoutes $routes
	 */
	public abstract function dispatch(ConfigureRoutes $routes): void;
}
