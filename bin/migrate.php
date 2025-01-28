#!/usr/bin/env php
<?php

use Nixx\EasyWorkerman\Core\Init;
use Nixx\EasyWorkerman\Core\Logger;
use Nixx\EasyWorkerman\Migration\SchemaMigration;

if( !defined('APP_ROOT') ) {
	exit('Define APP_ROOT before use it');
}
require_once APP_ROOT . '/vendor/autoload.php';

$commands = ['migrate', 'rollback', 'create'];

$cli = Init::config(false, [
	'command' => [
		'description' => 'Run command for migration: ' . implode(', ', $commands),
	],
]);

if( !in_array($cli->arguments->get('command'), $commands) ) {
	$cli->usage();
	exit;
}

Init::init($cli, 'migration');

try {
	//check migration schema
	$schema = new SchemaMigration(CONFIG['db']['migration_path'] ?? null ?: APP_ROOT . '/db/migrate');
	$schema->check();
	switch( $cli->arguments->get('command') ) {
		case 'migrate':
			$schema->migrate();
			break;
		case 'rollback':
			$schema->rollback($argv[2] ?? 1);
			break;
		case 'create':
			$schema->create();
			break;
	}
} catch (\Throwable $e) {
	Logger::$logger->error($e::class . ': ' . $e->getMessage());
	exit(1);
}
