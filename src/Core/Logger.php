<?php

namespace Nixx\EasyWorkerman\Core;

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\ByteStream;
use JetBrains\PhpStorm\Immutable;
use League\CLImate\CLImate;
use Monolog\Formatter\LineFormatter;

final class Logger {

	const TYPE_DEBUG = 'debug';
	const TYPE_INFO = 'info';
	const TYPE_WARN = 'warning';
	const TYPE_ERROR = 'error';
	const FORMAT = '[%datetime%] %channel%.%level_name%: %message% %context% %extra%' . PHP_EOL;
	const DATE_FORMAT = 'Y-m-d H:i:s.v';

	private ?string $level;
	private ?string $log_file;
	private string $name;
	public ?string $daemon;

	public static \Monolog\Logger $logger;

	#[Immutable(Immutable::PROTECTED_WRITE_SCOPE)]
	public static ByteStream\WritableStream $writable;

	/**
	 * Logger constructor.
	 * @param CLImate $cli
	 * @param string  $name
	 * @throws \Exception
	 */
	public function __construct(CLImate $cli, string $name) {
		$this->level = $cli->arguments->get('log_level');
		$this->log_file = $cli->arguments->get('log');
		$this->name = $name;
		$this->daemon = $cli->arguments->get('daemon');

		if( $this->daemon ) {
			if( !is_dir(pathinfo($this->log_file, PATHINFO_DIRNAME)) ) {
				mkdir(pathinfo($this->log_file, PATHINFO_DIRNAME));
			}
			if( file_exists($this->log_file) && !is_writable($this->log_file) || !file_exists($this->log_file) && !is_writable(pathinfo($this->log_file, PATHINFO_DIRNAME)) ) {
				throw new \Exception('Permission denied to write file: ' . $this->log_file);
			}
			if( !file_exists($this->log_file) ) {
				file_put_contents($this->log_file, '');
			}
		}
	}

	/**
	 * @param string $format
	 * @return \Monolog\Logger
	 */
	public function logger(string $format = Logger::FORMAT): \Monolog\Logger {
		if( $this->daemon ) {
			self::$writable = new ByteStream\WritableResourceStream(\fopen($this->log_file, 'a+'));
			$handler = new StreamHandler(self::$writable);
			$handler->setFormatter(new LineFormatter('[' . getmypid() . '] ' . $format, Logger::DATE_FORMAT, true));
		} else {
			self::$writable = ByteStream\getOutputBufferStream();
			$handler = new StreamHandler(self::$writable);
			$handler->setFormatter(new ConsoleFormatter($format, Logger::DATE_FORMAT, true));
		}
		$handler->setLevel($this->level);

		$logger = new \Monolog\Logger($this->name);
		$logger->pushHandler($handler);

		//Сохраняем прямой доступ к логгеру
		Logger::$logger = $logger;

		return $logger;
	}
}
