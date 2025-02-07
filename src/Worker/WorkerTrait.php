<?php

namespace Nixx\EasyWorkerman\Worker;

use Amp\Future\UnhandledFutureError;
use League\CLImate\CLImate;
use Revolt\EventLoop;
use Nixx\EasyWorkerman\Core\Init;
use Nixx\EasyWorkerman\Core\Logger;

/**
 * @property string $name
 */
trait WorkerTrait {

	protected CLImate $cli;
	protected ?string $process_name;

	/**
	 * @return void
	 */
	public abstract function onStart(): void;

	/**
	 * @param CLImate     $cli
	 * @param string      $name
	 * @param string|null $process_name
	 */
	public function configure(CLImate $cli, string $name, ?string $process_name = null): void {
		$this->cli = $cli;
		$this->name = $name;
		$this->process_name = $process_name;
		if( property_exists(static::class, 'status') ) {
			self::$status = self::STATUS_STARTING;
		}
		$this->onWorkerStart = [$this, 'onWorkerStarted'];
	}

	/**
	 * Процесс запустился
	 * @throws \Exception
	 */
	final public function onWorkerStarted(): void {
		if( $this->process_name ) {
			@cli_set_process_title($this->process_name);
		}

		try {
			//Инициализируем
			Init::init($this->cli, $this->name);

			//Обработка не пойманных ошибок в потоках
			EventLoop::setErrorHandler(function(\Throwable $e): void {
				if( $e instanceof UnhandledFutureError && $e->getPrevious() ) {
					$e = $e->getMessage();
				}
				Logger::$logger->error('Exception handler: ' . get_class($e) . ', ' . $e->getMessage() . ', ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString());
			});

			//Устанавливаем статус, что запустились
			$this->worker_ready = true;
		} catch (\Throwable $e) {
			Logger::$logger->error('Stop WorkerTrait worker with error: ' . get_class($e) . ', ' . $e->getMessage() . ', ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString());
			exit;
		}

		//Вызываем обработчик
		$this->onStart();
	}
}
