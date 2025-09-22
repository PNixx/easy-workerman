<?php

namespace Nixx\EasyWorkerman\Core;

use League\CLImate\CLImate;
use League\CLImate\Exceptions\InvalidArgumentException;
use PNixx\DelayedJob\DelayedJob;

class Init {

	/**
	 * Конфигурация запуска
	 * @param bool   $can_daemon
	 * @param array  $extend
	 * @param string $config_file
	 * @return CLImate
	 */
	public static function config(bool $can_daemon = true, array $extend = [], string $config_file = 'config/secrets.ini'): CLImate {
		if( getenv('DEVELOPMENT') == 'true' ) {
			define('DEVELOPMENT', true);
			echo "\e[1;33m⚠️ Run with development mode\e[0m\n";
		}

		$arguments = array_filter(array_merge([
			'daemon'    => $can_daemon ? [
				'prefix'      => 'd',
				'description' => 'Run as daemon',
				'noValue'     => true,
			] : null,
			'log'       => [
				'prefix'       => 'l',
				'longPrefix'   => 'log',
				'description'  => 'Log file',
				'defaultValue' => APP_ROOT . '/log/server.log',
			],
			'log_level' => [
				'prefix'       => 'v',
				'longPrefix'   => 'log_level',
				'description'  => 'Log level, available: ' . Logger::TYPE_DEBUG . ',' . Logger::TYPE_INFO . ',' . Logger::TYPE_WARN . ',' . Logger::TYPE_ERROR,
				'defaultValue' => Logger::TYPE_ERROR,
			],
			'help'      => [
				'prefix'      => 'h',
				'longPrefix'  => 'help',
				'description' => 'Prints a usage statement',
				'noValue'     => true,
			],
		], $extend));

		$cli = new CLImate();
		$cli->arguments->add($arguments);
		try {
			$cli->arguments->parse();
		} catch (InvalidArgumentException $e) {
			exit($e->getMessage() . PHP_EOL);
		}
		if( $cli->arguments->get('help') ) {
			$cli->usage();
			exit;
		}

		//Читаем конфиги
		$config = parse_ini_file(APP_ROOT . '/' . $config_file, true);
		define('CONFIG', $config);

		error_reporting(E_ALL);
		ini_set('display_errors', 1);

		return $cli;
	}

	/**
	 * Инициализация классов и подключений
	 * @param CLImate $cli
	 * @param string  $name
	 * @return void
	 * @throws \Exception
	 */
	public static function init(CLImate $cli, string $name): void {
		set_error_handler(Init::errorHandler(...));

		//Устанавливаем время в UTC, т.к. база вся работает только с ним
		date_default_timezone_set(CONFIG['timezone'] ?? 'Europe/Moscow');

		$logger = new Logger($cli, $name);
		$logger->logger();

		//Инициализируем подключение к базе (основное)
		if( isset(CONFIG['db']) ) {
			Postgres::init(CONFIG['db'], $name);
		}

		//Подключаемся к редису
		if( isset(CONFIG['redis']) ) {
			new Redis(CONFIG['redis']);
			if( class_exists(DelayedJob::class) ) {
				new DelayedJob(CONFIG['redis']['url']);
			}
		}
	}

	/**
	 * @param $severity
	 * @param $message
	 * @param $filename
	 * @param $lineno
	 * @return bool
	 * @throws \ErrorException
	 */
	public static function errorHandler($severity, $message, $filename, $lineno): bool {
		if( error_reporting() == 0 ) {
			return false;
		}
		if( $severity == E_DEPRECATED ) {
			Logger::$logger->warning($message . ', ' . $filename . ':' . $lineno);
			return true;
		} elseif( error_reporting() & $severity ) {
			throw new \ErrorException($message . ', ' . $filename . ':' . $lineno, 0, $severity, $filename, $lineno);
		}
		return false;
	}
}
