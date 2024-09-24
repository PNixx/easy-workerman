<?php

namespace Nixx\EasyWorkerman\Worker;

use Amp\Cancellation;
use League\CLImate\CLImate;
use Nixx\EasyWorkerman\Core\Controller;
use Nixx\EasyWorkerman\Error\NotFoundError;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

abstract class ApiWorker extends HttpWorker {

	/**
	 * API WebServer constructor.
	 * @param CLImate $cli
	 * @param string  $name
	 * @param int     $port
	 */
	public function __construct(CLImate $cli, string $name, int $port, array $context = []) {
		parent::__construct($cli, 'API', $name, $port, $context);
	}

	/**
	 * @param string $method
	 * @param string $path
	 * @return array
	 */
	abstract function route(string $method, string $path): array;

	protected function doResponse(string $method, string $path, array $params, Request $request, Cancellation $cancellation): Response {
		try {
			//Fetch class and action name by path
			$route = $this->route($method, $path);

			/** @var Controller $controller */
			$controller = new $route[0]($params, $request);

			//Check action is existing
			if( !method_exists($controller, $route[1]) ) {
				return $this->failed('Action not found', 404);
			}

			//Block all bots
			if( $controller->isBot() ) {
				return new Response(401);
			}

			//Execute action
			if( !in_array($route[1], $controller->skipBeforeAction) ) {
				$controller->beforeAction();
			}
			$result = $controller->{$route[1]}(...$route['vars']);

			//Return result
			if( $result instanceof Response ) {
				return $result;
			} else {
				return $this->success($result);
			}
		} catch (NotFoundError $e) {
			return $this->failed($e->getMessage(), 404);
		}
	}
}
