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
	public static string $lock_file = __DIR__ . '/../log/monitor.lock';

	/**
	 * Pause monitor
	 */
	public static function pause(): void {
		file_put_contents(self::$lock_file, time());
	}

	/**
	 * Resume monitor
	 * @return void
	 */
	public static function resume(): void {
		clearstatcache();
		if( is_file(self::$lock_file) ) {
			unlink(self::$lock_file);
		}
	}

	/**
	 * Whether monitor is paused
	 * @return bool
	 */
	public static function isPaused(): bool {
		clearstatcache();
		return file_exists(self::$lock_file);
	}

	/**
	 * FileMonitor constructor.
	 * @param string $dir
	 * @param array  $extensions
	 * @param array  $options
	 */
	public function __construct(string $dir, array $extensions, array $options = []) {
		self::resume();
		$this->paths = (array)$dir;
		$this->extensions = $extensions;
		if( !Worker::getAllWorkers() ) {
			return;
		}
		$disable_functions = explode(',', ini_get('disable_functions'));
		if( in_array('exec', $disable_functions, true) ) {
			echo "\nMonitor file change turned off because exec() has been disabled by disable_functions setting in " . PHP_CONFIG_FILE_PATH . "/php.ini\n";
		} else {
			Timer::add(1, $this->checkAllFilesChange(...));
		}

		$memory_limit = $this->getMemoryLimit($options['memory_limit'] ?? null);
		if( $memory_limit ) {
			Timer::add(60, [$this, 'checkMemory'], [$memory_limit]);
		}
	}

	/**
	 * @param string $monitor_dir
	 * @return bool
	 */
	public function checkFilesChange(string $monitor_dir): bool {
		static $last_mtime, $too_many_files_check;
		if( !$last_mtime ) {
			$last_mtime = time();
		}
		clearstatcache();
		if( !is_dir($monitor_dir) ) {
			if( !is_file($monitor_dir) ) {
				return false;
			}
			$iterator = [new SplFileInfo($monitor_dir)];
		} else {
			// recursive traversal directory
			$directory_iterator = new RecursiveDirectoryIterator($monitor_dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS);
			$iterator = new RecursiveIteratorIterator($directory_iterator);
		}

		$count = 0;
		foreach( $iterator as $file ) {
			$count++;
			/** var SplFileInfo $file */
			if( is_dir($file->getRealPath()) ) {
				continue;
			}
			// check mtime
			if( in_array($file->getExtension(), $this->extensions, true) && $last_mtime < $file->getMTime() ) {
				$var = 0;
				exec('"' . PHP_BINARY . '" -l "' . $file . '"', $out, $var);
				$last_mtime = $file->getMTime();
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
		if( !$too_many_files_check && $count > 1000 ) {
			echo "Monitor: There are too many files ($count files) in $monitor_dir which makes file monitoring very slow\n";
			$too_many_files_check = 1;
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
	 * @param float|int $memory_limit
	 */
	public function checkMemory(float|int $memory_limit): void {
		if( self::isPaused() || $memory_limit <= 0 ) {
			return;
		}
		$ppid = posix_getppid();
		$children_file = "/proc/$ppid/task/$ppid/children";
		if( !is_file($children_file) || !($children = file_get_contents($children_file)) ) {
			return;
		}
		foreach( explode(' ', $children) as $pid ) {
			$pid = (int)$pid;
			$status_file = "/proc/$pid/status";
			if( !is_file($status_file) || !($status = file_get_contents($status_file)) ) {
				continue;
			}
			$mem = 0;
			if( preg_match('/VmRSS\s*?:\s*?(\d+?)\s*?kB/', $status, $match) ) {
				$mem = $match[1];
			}
			$mem = (int)($mem / 1024);
			if( $mem >= $memory_limit ) {
				posix_kill($pid, SIGINT);
			}
		}
	}

	/**
	 * Get memory limit
	 * @param int|null $memory_limit
	 * @return float|int
	 */
	protected function getMemoryLimit(?int $memory_limit): float|int {
		if( $memory_limit === 0 ) {
			return 0;
		}
		$use_php_ini = false;
		if( !$memory_limit ) {
			$memory_limit = ini_get('memory_limit');
			$use_php_ini = true;
		}

		if( $memory_limit == -1 ) {
			return 0;
		}
		$unit = strtolower($memory_limit[strlen($memory_limit) - 1]);
		if( $unit === 'g' ) {
			$memory_limit = 1024 * (int)$memory_limit;
		} else {
			if( $unit === 'm' ) {
				$memory_limit = (int)$memory_limit;
			} else {
				if( $unit === 'k' ) {
					$memory_limit = ((int)$memory_limit / 1024);
				} else {
					$memory_limit = ((int)$memory_limit / (1024 * 1024));
				}
			}
		}
		if( $memory_limit < 30 ) {
			$memory_limit = 30;
		}
		if( $use_php_ini ) {
			$memory_limit = (int)(0.8 * $memory_limit);
		}
		return $memory_limit;
	}
}
