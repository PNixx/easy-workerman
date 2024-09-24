<?php

namespace Nixx\EasyWorkerman\Worker;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Sql\SqlException;
use League\CLImate\CLImate;
use Monolog\Level;
use Nixx\EasyWorkerman\Core\Logger;
use Nixx\EasyWorkerman\Error\RequestError;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

abstract class HttpWorker extends Worker {
	use WorkerTrait;

	private \SplObjectStorage $requests;
	protected bool $worker_ready = false;

	const METHOD_ALLOWED = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'];

	/**
	 * HTTP WebServer constructor.
	 * @param CLImate $cli
	 * @param string  $name
	 * @param string  $process_name
	 * @param int     $port
	 * @param array   $context
	 */
	public function __construct(CLImate $cli, string $name, string $process_name, int $port, array $context = []) {
		$this->configure($cli, $name, $process_name);
		$this->requests = new \SplObjectStorage;
		parent::__construct('http://0.0.0.0:' . $port, $context);
		$this->onMessage = [$this, 'onMessageReceived'];
		$this->onClose = [$this, 'onClosed'];
		$this->onWorkerReload = [$this, 'onReload'];
		$this->onWorkerStop = [$this, 'onStop'];
		$this->reloadable = false;
		$this->reusePort = true;
		self::$stopTimeout = 30;
	}

	/**
	 * @param TcpConnection $connection
	 * @param Request       $request
	 * @throws \Throwable
	 */
	public function onMessageReceived(TcpConnection $connection, Request $request): void {
		$cancellation = new DeferredCancellation();
		if( self::$status == self::STATUS_RUNNING && $this->worker_ready ) {
			$this->requests->attach($connection, $cancellation);

			//Делаем запрос
			$time = microtime(true);
			$response = $this->execute($request, $cancellation->getCancellation());
			if( Logger::$logger->isHandling(Level::Debug) ) {
				Logger::$logger->debug('Completed ' . $response->getStatusCode() . ' ' . (Response::PHRASES[$response->getStatusCode()] ?? '') . ' in ' . round((microtime(true) - $time) * 1000, 2) . 'ms');
			}

			//Добавляем заголовки CORS
			$response->withHeaders(array_filter([
				'Access-Control-Allow-Origin' => $request->header('Origin'),
				'Server'                      => CONFIG['api']['server_name'] ?? 'Easy server',
			]));

			//Закрываем коннект с ответом
			$this->requests->detach($connection);
			if( $connection->getStatus() == TcpConnection::STATUS_ESTABLISHED ) {
				$connection->close($response);
			}
		} else {
			$connection->close($this->response(410, ['status' => 'error', 'message' => 'Reloading']));
		}
	}

	public function onStart(): void {
		Logger::$logger->alert('Worker started');
	}

	/**
	 * @param TcpConnection $connection
	 */
	public function onClosed(TcpConnection $connection): void {
		if( $this->requests->contains($connection) ) {
			/** @var DeferredCancellation $cancellation */
			$cancellation = $this->requests[$connection];
			$this->requests->detach($connection);
			$cancellation->cancel(new \Exception('Client connection closed'));
		}
	}

	/**
	 * @throws \Throwable
	 */
	public function onReload(): void {
		self::$status = self::STATUS_RELOADING;
		$this->worker_ready = false;
		self::stopAll();
	}

	public function onStop(): void {}

	/**
	 * @param string       $method
	 * @param string       $path
	 * @param array        $params
	 * @param Request      $request
	 * @param Cancellation $cancellation
	 * @return Response
	 * @throws CancelledException
	 * @throws SqlException
	 */
	abstract protected function doResponse(string $method, string $path, array $params, Request $request, Cancellation $cancellation): Response;

	/**
	 * @param Request      $request
	 * @param Cancellation $cancellation
	 * @return Response
	 */
	public function execute(Request $request, Cancellation $cancellation): Response {
		try {
			if( Logger::$logger->isHandling(Level::Debug) ) {
				Logger::$logger->debug($request->method() . ' http://' . $request->host() . $request->uri() . ', ' . json_encode($request->post(), JSON_UNESCAPED_UNICODE));
			}
			if( $request->method() === 'HEAD' ) {
				return new Response();
			}
			if( $request->method() === 'OPTIONS' ) {
				return new Response(200, [
					'Access-Control-Allow-Methods' => strtoupper(implode(', ', ['HEAD', ...static::METHOD_ALLOWED])),
					'Access-Control-Allow-Headers' => 'api,content-type,authorization',
				]);
			}
			if( !in_array($request->method(), static::METHOD_ALLOWED) ) {
				return new Response(405);
			}

			//Парсим параметры
			parse_str($request->queryString(), $params);
			if( $request->method() != 'GET' ) {
				$params = array_merge($params, $request->post() ?: [], $request->file() ?: []);
			}

			//Возвращаем json
			return $this->doResponse($request->method(), $request->path(), $params, $request, $cancellation);
		} catch (RequestError $e) {
			return $this->failed($e->getMessage(), $e->getCode() ?: 400);
		} catch (CancelledException $e) {
			Logger::$logger->debug('CancelledException: ' . $e->getPrevious()?->getMessage() ?: $e->getMessage());
			return new Response(417);
		} catch (SqlException $e) {
			Logger::$logger->warning(get_class($e) . ': ' . $e->getMessage());
			return $this->error($e);
		} catch (\Throwable $e) {
			Logger::$logger->error($request->method() . ': ' . $request->uri() . PHP_EOL . ' : ' . get_class($e) . ' (' . $e->getCode() . '), ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), $request->post());
			return $this->error($e);
		}
	}

	/**
	 * @param array|null $data
	 * @return Response
	 */
	protected function success(?array $data): Response {
		if( is_null($data) ) {
			return new Response(204);
		}
		return $this->response(200, $data);
	}

	/**
	 * @param mixed   $message
	 * @param int     $status
	 * @return Response
	 */
	protected function failed(mixed $message, int $status = 400): Response {
		return $this->response($status, ['status' => 'error', 'message' => $message]);
	}

	/**
	 * @param \Throwable  $e
	 * @param string|null $message
	 * @return Response
	 */
	protected function error(\Throwable $e, ?string $message = null): Response {
		return $this->response(500, array_filter([
			'status'  => 'error',
			'message' => 'Request error. Please, contact to our support team.',
			'details' => defined('DEVELOPMENT') ? $message : null,
		]));
	}

	/**
	 * @param int   $status
	 * @param array $data
	 * @return Response
	 */
	protected function response(int $status, array $data): Response {
		return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
	}
}
