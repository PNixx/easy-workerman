<?php

namespace Nixx\EasyWorkerman\Migration;

use Amp;
use Amp\Postgres\PostgresQueryError;
use Amp\Process\Process;
use Nixx\EasyWorkerman\Core\Logger;
use Nixx\EasyWorkerman\Core\PgTransaction;

class SchemaMigration extends Migration {
	const TABLE = 'schema_migrations';

	public function __construct(protected readonly string $directory) {
		parent::__construct();
	}

	public function check(): void {
		try {
			$this->driver()->count(self::TABLE);
		} catch (PostgresQueryError $e) {
			if( $e->getDiagnostics()['sqlstate'] == '42P01' ) {
				$this->up();
			} else {
				throw $e;
			}
		}
	}

	/**
	 * @return void
	 * @throws \Throwable
	 */
	public function migrate(): void {
		$files = array_diff_key($this->files(), array_flip($this->versions()));
		foreach( $files as $file ) {
			Logger::$logger->alert(str_pad('== ' . $this->version($file) . ' ' . $this->className($file) . ': migrating ==', 80, '='));
			$time = microtime(true);
			$this->driver()->transaction(function(PgTransaction $transaction) use ($file) {
				$migrate = $this->klass($file, $transaction);
				$migrate->up();
				$transaction->insert(self::TABLE, ['version' => $this->version($file)]);
			});
			Logger::$logger->alert(str_pad('== ' . $this->version($file) . ' ' . $this->className($file) . ': migrated (' . round(microtime(true) - $time, 4) . 's) ==', 80, '='));
		}
		$this->dump();
	}

	/**
	 * @return void
	 * @throws \Throwable
	 */
	public function rollback(int $count = 1): void {
		for( $i = 0; $i < $count; $i++ ) {
			$files = array_intersect_key($this->files(), array_flip($this->versions()));
			if( $files ) {
				$file = end($files);
				Logger::$logger->alert(str_pad('== ' . $this->version($file) . ' ' . $this->className($file) . ': reverting ==', 80, '='));
				$time = microtime(true);
				$this->driver()->transaction(function(PgTransaction $transaction) use ($file) {
					$migrate = $this->klass($file, $transaction);
					$migrate->down();
					$transaction->delete(self::TABLE, ['version' => $this->version($file)]);
				});
				Logger::$logger->alert(str_pad('== ' . $this->version($file) . ' ' . $this->className($file) . ': reverted (' . round(microtime(true) - $time, 4) . 's) ==', 80, '='));
			}
		}
		$this->dump();
	}

	/**
	 * @return void
	 */
	public function create(): void {
		echo 'Write unique class name, for example: CreateUser: ';
		$name = $argv[2] ?? readline();
		if( empty($name) ) {
			Logger::$logger->alert('Empty, skipped');
		}
		foreach($this->files() as $file) {
			if( $this->name($file) == $name ) {
				Logger::$logger->alert('Migration already exist');
			}
		}
		$file_name = $this->directory . '/' . time() . '_' . strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)) . '.php';
		$data = <<<PHP
<?php

use Nixx\EasyWorkerman\Migration\Migration;

class $name extends Migration {

	public function up(): void {
		
	}

	public function down(): void {
	
	}
}
PHP;
		if( !is_dir($this->directory) ) {
			mkdir($this->directory);
		}
		file_put_contents($file_name, $data);
		Logger::$logger->alert('Created: ' . $file_name);
	}

	public function dump(): void {
		//Schema dump
		$process = Process::start('PGPASSWORD=' . escapeshellarg(CONFIG['db']['password']) . ' pg_dump -c -f db/schema.sql --no-tablespaces --no-security-labels --no-owner --schema-only -d ' . escapeshellarg(CONFIG['db']['database']) .
			' -h ' . escapeshellarg(CONFIG['db']['host']) . ' -p ' . escapeshellarg(CONFIG['db']['port']) .
			' -U ' . escapeshellarg(CONFIG['db']['username']), APP_ROOT);
		Amp\async(fn () => Amp\ByteStream\pipe($process->getStdout(), Amp\ByteStream\getStdout()));
		Amp\async(fn () => Amp\ByteStream\pipe($process->getStderr(), Amp\ByteStream\getStderr()));
		$code = $process->join();
		if( $code > 0 ) {
			Logger::$logger->error('Process exit with code: ' . $code);
		}

		//Add schema_migrations data
		$process = Process::start('PGPASSWORD=' . escapeshellarg(CONFIG['db']['password']) . ' pg_dump -t schema_migrations -a -d ' . escapeshellarg(CONFIG['db']['database']) .
			' -h ' . escapeshellarg(CONFIG['db']['host']) . ' -p ' . escapeshellarg(CONFIG['db']['port']) .
			' -U ' . escapeshellarg(CONFIG['db']['username']) . ' >> db/schema.sql', APP_ROOT);
		Amp\async(fn () => Amp\ByteStream\pipe($process->getStdout(), Amp\ByteStream\getStdout()));
		Amp\async(fn () => Amp\ByteStream\pipe($process->getStderr(), Amp\ByteStream\getStderr()));
		$code = $process->join();
		if( $code > 0 ) {
			Logger::$logger->error('Process exit with code: ' . $code);
		}
	}

	/**
	 * @return array
	 */
	protected function versions(): array {
		return array_column($this->driver()->select(self::TABLE, [], ['version']), 'version');
	}

	/**
	 * @return array
	 */
	protected function files(): array {
		$files = [];
		foreach( glob($this->directory . '/*.php') as $file ) {
			$files[$this->version($file)] = $file;
		}
		ksort($files);
		return $files;
	}

	/**
	 * @param string $file
	 * @return string
	 */
	protected function version(string $file): string {
		return preg_replace('/^(\d+)_.*?$/', '$1', pathinfo($file, PATHINFO_FILENAME));
	}

	/**
	 * Return migration class name
	 * @param string $file
	 * @return string
	 */
	protected function name(string $file): string {
		return preg_replace('/^\d+_(.*?)$/', '$1', pathinfo($file, PATHINFO_FILENAME));
	}

	/**
	 * @param string $file
	 * @return string
	 */
	protected function className(string $file): string {
		return str_replace(' ', '', ucwords(str_replace('_', ' ', $this->name($file))));
	}

	/**
	 * @param string $file
	 * @return Migration
	 */
	protected function klass(string $file): Migration {
		require_once $file;
		$class = $this->className($file);
		return new $class;
	}

	/**
	 * Create table
	 * @return void
	 */
	public function up(): void {
		$this->driver()->query('CREATE TABLE ' . self::TABLE . ' (version int PRIMARY KEY)');
	}

	public function down(): void {}
}
