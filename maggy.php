#!/usr/bin/env php
<?php

require 'Database.php';
require 'MaggyParser.php';
require 'helpers.php';

function help(): void {
	echo "List of commands:\n";
	echo "\thelp — show this message\n";
}

function setup(): void {
	$has_config = file_exists('./config.ini');
	$has_migrations = file_exists('./migration');

	if ($has_config && $has_migrations) {
		die("Maggy is already active.\n");
	}

	if (!$has_config) {
		$config_file = fopen('./config.ini', 'w');

		$default_config = <<<STR
		; Example Maggy configuration:
		; [dbconfig]
		; host = "localhost"
		; SQL username
		; user = "MyUser"
		; Password for user (empty string for 'NO')
		; password = "password"
		; Name of the database you wish Maggy to use
		; db_name = "ExampleDB"
		STR;

		fwrite($config_file, $default_config);
	}

	if (!$has_migrations) {
		mkdir('./migration');
	}
}

function migrate(Database $database, bool $view = false): ?string {
	$version = $database->get_version();

	$db_name = $database->config['db_name'];
    $maggy_parser = new MaggyParser(get_migration_path($version), $db_name);
    $migration = $maggy_parser->parse_migration();

	$sql = $migration['global'] . $migration['up'];

	if ($view) return $sql;

	$config = $database->config;

	shell_exec("echo ".escapeshellarg($sql)." | mysql --user=\"{$config['user']}\" --database=\"{$config['db_name']}\"");

    return null;
}

function rollback(Database $database, bool $view = false): ?string {
	$version = $database->get_version();

	if ($version == 0) {
		echo "Can't rollback: already at earliest version.\n";
		return null;
	}

	$db_name = $database->config['db_name'];
    $maggy_parser = new MaggyParser(get_migration_path($version - 1), $db_name);
	$migration = $maggy_parser->parse_migration();

	$sql = $migration['global'] . $migration['down'];

	if ($view) return $sql;

	$config = $database->config;

	shell_exec("echo ".escapeshellarg($sql)." | mysql --user=\"{$config['user']}\" --database=\"{$config['db_name']}\"");

    return null;
}

function test(): bool {
	$database = Database::test_db();

	$db_schema = $database->dump_db_definitions();
	$db_data   = $database->dump_db_data();

	migrate($database);

	$migrate_db_schema = $database->dump_db_definitions();
	$migrate_db_data   = $database->dump_db_data();

	rollback($database);

	$new_db_schema = $database->dump_db_definitions();
	$new_db_data   = $database->dump_db_data();

	$diff_options = "--new-line-format='+ %l\n' --old-line-format='- %l\n' --unchanged-line-format=''";
	if ($new_db_schema != $db_schema) {
		echo "Error: Part of --@Up segment not handled in --@Down segments.\n\n";

		$diff = shell_exec("diff <(echo ".escapeshellarg($db_schema).") <(echo ".escapeshellarg($new_db_schema).") $diff_options") ?? "";
		echo cull_diff($diff)."\n";

		$diff_down = shell_exec("diff <(echo ".escapeshellarg($migrate_db_schema).") <(echo ".escapeshellarg($new_db_schema).") $diff_options") ?? "";

		$hints = parse_diff($diff, $diff_down);

		foreach ($hints as $hint) {
			echo "\nHint: $hint\n";
		}
	}

	if ($new_db_data != $db_data) {
		echo "Error: Data loss or corruption detected. Try adding `--#IgnoreData`.\n\n";
		system("diff <(echo ".escapeshellarg($db_data).") <(echo ".escapeshellarg($new_db_data).") $diff_options");
	}

	if ($new_db_data == $db_data && $new_db_schema == $db_schema) {
		echo "Success!\n";
		return true;
	}

	return false;
}

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
