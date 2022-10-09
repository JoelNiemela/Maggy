#!/usr/bin/env php
<?php

require 'Database.php';
require 'MaggyParser.php';
require 'helpers.php';
require 'commands.php';

if ($argc < 2) {
	die("Expected a command. Type `maggy help` for more information.\n");
}

$args = array_slice($argv, 1);
$commands = [];
$flags    = [];
$errors   = [];

foreach ($args as $i => $arg) {
	$matches = [];
	if (preg_match('/^((?<scope>[a-z]+):)?(?<command>[a-z]+)$/', $arg, $matches)) {
		$commands[] = $arg;
	} elseif (preg_match('/^--(?<flag>[a-z]+)(=(?<val>[a-z]*))?$/', $arg, $matches)) {
		$flags[$matches['flag']] = $matches['val'] ?? '';
	} elseif (preg_match('/^-(?<flag>[a-z])(=(?<val>[a-z]*))?$/', $arg, $matches)) {
		$flags[$matches['flag']] = $matches['val'] ?? '';
	} else {
		$errors[] = ['pos' => $i, 'err' => $arg];
	}
}

foreach ($errors as $error) {
	echo "Error: couldn't parse argument #{$error['pos']} '{$error['err']}'\n";
}

$command = $commands[0] ?? '';
$args = array_slice($commands, 1);

switch ($command) {
	case '':
		if (isset($flags['help']) || isset($flags['h'])) {
			help();
			break;
		}
		break;
	case 'help':
		help();
		break;
	case 'setup':
		setup();
		break;
	case 'dump':
		$database = Database::db();

		echo $database->dump_db_all()."\n";
		break;
	case 'test:dump':
		$database = Database::test_db();

		echo $database->dump_db_all()."\n";
		break;
	case 'test:version':
		$database = Database::test_db();

		echo $database->get_version()."\n";
		break;
	case 'db:version':
		$database = Database::db();

		echo $database->get_version()."\n";
		break;
	case 'test':
		test();
		break;
	case 'migrate':
		$view = ($args[0] ?? '') == 'view';

		if (!$view && !test()) break;

		$database = Database::db();

		echo migrate($database, $view);
		break;
	case 'rollback':
		$database = Database::db();

		$view = ($args[0] ?? '') == 'view';

		echo rollback($database, $view);
		break;
	default:
		die("Unknown command `$command`. Type `maggy help` for more information.\n");
		break;
}
