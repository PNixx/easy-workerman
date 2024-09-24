<?php

namespace Nixx\EasyWorkerman\Worker;

use League\CLImate\CLImate;
use Nixx\EasyWorkerman\Core\Monitor;
use Workerman\Worker;

final class MonitorWorker extends Worker {

	public function __construct(protected readonly CLImate $cli) {
		parent::__construct();
		$this->name = 'Monitor';
		$this->onWorkerStart = [$this, 'onWorkerStarted'];
	}

	/**
	 * Процесс запустился
	 * @throws \Exception
	 */
	public final function onWorkerStarted(): void {
		@cli_set_process_title('monitor-worker');

		//Инициализируем
		new Monitor(APP_ROOT . '/src', ['php', 'html', 'htm', 'env', 'ini']);
	}
}
