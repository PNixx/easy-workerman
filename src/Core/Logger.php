<?php

namespace Nixx\EasyWorkerman\Core;

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\ByteStream;
use JetBrains\PhpStorm\Immutable;
use League\CLImate\CLImate;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;

final class Logger {

	const TYPE_DEBUG = 'debug';
	const TYPE_INFO = 'info';
	const TYPE_WARN = 'warning';
	const TYPE_ERROR = 'error';
	const FORMAT = '[%datetime%] %channel%.%level_name%: %message% %context% %extra%' . PHP_EOL;
	const DATE_FORMAT = 'Y-m-d H:i:s.v';

	/** @var Logger::TYPE_* */
	private string $level;
	private ?string $log_file;
	public ?string $daemon;

	public static \Monolog\Logger $logger;

	protected static ByteStream\WritableStream $writable;

	/**
	 * Logger constructor.
	 * @param CLImate $cli
	 * @param string  $name
	 * @throws \Exception
	 */
	public function __construct(CLImate $cli, private readonly string $name) {
		$level = $cli->arguments->get('log_level');
		$this->level = in_array($level, [self::TYPE_DEBUG, self::TYPE_INFO, self::TYPE_WARN, self::TYPE_ERROR], true) ? $level : Logger::TYPE_ERROR;
		$this->log_file = (string)$cli->arguments->get('log') ?: null;
		$this->daemon = (string)$cli->arguments->get('daemon');

		if( $this->daemon && $this->log_file !== null ) {
			$dirname = pathinfo($this->log_file, PATHINFO_DIRNAME);
			if( !is_dir($dirname) ) {
				mkdir($dirname);
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
	 * @throws \Exception
	 */
	public function logger(string $format = Logger::FORMAT): \Monolog\Logger {
		if( $this->daemon && $this->log_file !== null ) {
			$stream = \fopen($this->log_file, 'a');
			if( !$stream ) {
				throw new \Exception('Permission denied to write file: ' . $this->log_file);
			}
			self::$writable = new ByteStream\WritableResourceStream($stream);
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
