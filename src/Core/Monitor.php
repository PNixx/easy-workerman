<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Nixx\EasyWorkerman\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Class FileMonitor
 * @package process
 */
final class Monitor {
	/**
	 * @var array
	 */
	protected array $paths = [];

	/**
	 * @var array
	 */
	protected array $extensions = [];

	/**
	 * @var string
	 */
	public static string $lockFile = __DIR__ . '/../log/monitor.lock';

	/**
	 * Pause monitor
	 */
	public static function pause(): void {
		file_put_contents(self::$lockFile, time());
	}

	/**
	 * Resume monitor
	 * @return void
	 */
	public static function resume(): void {
		clearstatcache();
		if( is_file(self::$lockFile) ) {
			unlink(self::$lockFile);
		}
	}

	/**
	 * Whether monitor is paused
	 * @return bool
	 */
	public static function isPaused(): bool {
		clearstatcache();
		return file_exists(self::$lockFile);
	}

	/**
	 * FileMonitor constructor.
	 * @param       $monitorDir
	 * @param       $monitorExtensions
	 * @param array $options
	 */
	public function __construct($monitorDir, $monitorExtensions, array $options = []) {
		self::resume();
		$this->paths = (array)$monitorDir;
		$this->extensions = $monitorExtensions;
		if( !Worker::getAllWorkers() ) {
			return;
		}
		$disableFunctions = explode(',', ini_get('disable_functions'));
		if( in_array('exec', $disableFunctions, true) ) {
			echo "\nMonitor file change turned off because exec() has been disabled by disable_functions setting in " . PHP_CONFIG_FILE_PATH . "/php.ini\n";
		} else {
			Timer::add(1, $this->checkAllFilesChange(...));
		}

		$memoryLimit = $this->getMemoryLimit($options['memory_limit'] ?? null);
		if( $memoryLimit ) {
			Timer::add(60, [$this, 'checkMemory'], [$memoryLimit]);
		}
	}

	/**
	 * @param $monitorDir
	 * @return bool
	 */
	public function checkFilesChange($monitorDir): bool {
		static $lastMtime, $tooManyFilesCheck;
		if( !$lastMtime ) {
			$lastMtime = time();
		}
		clearstatcache();
		if( !is_dir($monitorDir) ) {
			if( !is_file($monitorDir) ) {
				return false;
			}
			$iterator = [new SplFileInfo($monitorDir)];
		} else {
			// recursive traversal directory
			$dirIterator = new RecursiveDirectoryIterator($monitorDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS);
			$iterator = new RecursiveIteratorIterator($dirIterator);
		}

		$count = 0;
		foreach( $iterator as $file ) {
			$count++;
			/** var SplFileInfo $file */
			if( is_dir($file->getRealPath()) ) {
				continue;
			}
			// check mtime
			if( in_array($file->getExtension(), $this->extensions, true) && $lastMtime < $file->getMTime() ) {
				$var = 0;
				exec('"' . PHP_BINARY . '" -l "' . $file . '"', $out, $var);
				$lastMtime = $file->getMTime();
				if( $var ) {
					continue;
				}
				echo str_replace(APP_ROOT, '', $file) . " update and reload\n";
				// send SIGUSR1 signal to master process for reload
				if( DIRECTORY_SEPARATOR === '/' ) {
					posix_kill(posix_getppid(), SIGUSR1);
				} else {
					return true;
				}
				break;
			}
		}
		if( !$tooManyFilesCheck && $count > 1000 ) {
			echo "Monitor: There are too many files ($count files) in $monitorDir which makes file monitoring very slow\n";
			$tooManyFilesCheck = 1;
		}
		return false;
	}

	/**
	 * @return void
	 */
	public function checkAllFilesChange(): void {
		if( self::isPaused() ) {
			return;
		}
		foreach( $this->paths as $path ) {
			if( $this->checkFilesChange($path) ) {
				return;
			}
		}
	}

	/**
	 * @param $memoryLimit
	 */
	public function checkMemory($memoryLimit): void {
		if( self::isPaused() || $memoryLimit <= 0 ) {
			return;
		}
		$ppid = posix_getppid();
		$childrenFile = "/proc/$ppid/task/$ppid/children";
		if( !is_file($childrenFile) || !($children = file_get_contents($childrenFile)) ) {
			return;
		}
		foreach( explode(' ', $children) as $pid ) {
			$pid = (int)$pid;
			$statusFile = "/proc/$pid/status";
			if( !is_file($statusFile) || !($status = file_get_contents($statusFile)) ) {
				continue;
			}
			$mem = 0;
			if( preg_match('/VmRSS\s*?:\s*?(\d+?)\s*?kB/', $status, $match) ) {
				$mem = $match[1];
			}
			$mem = (int)($mem / 1024);
			if( $mem >= $memoryLimit ) {
				posix_kill($pid, SIGINT);
			}
		}
	}

	/**
	 * Get memory limit
	 * @param int|null $memoryLimit
	 * @return float|int
	 */
	protected function getMemoryLimit(?int $memoryLimit): float|int {
		if( $memoryLimit === 0 ) {
			return 0;
		}
		$usePhpIni = false;
		if( !$memoryLimit ) {
			$memoryLimit = ini_get('memory_limit');
			$usePhpIni = true;
		}

		if( $memoryLimit == -1 ) {
			return 0;
		}
		$unit = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
		if( $unit === 'g' ) {
			$memoryLimit = 1024 * (int)$memoryLimit;
		} else {
			if( $unit === 'm' ) {
				$memoryLimit = (int)$memoryLimit;
			} else {
				if( $unit === 'k' ) {
					$memoryLimit = ((int)$memoryLimit / 1024);
				} else {
					$memoryLimit = ((int)$memoryLimit / (1024 * 1024));
				}
			}
		}
		if( $memoryLimit < 30 ) {
			$memoryLimit = 30;
		}
		if( $usePhpIni ) {
			$memoryLimit = (int)(0.8 * $memoryLimit);
		}
		return $memoryLimit;
	}
}
