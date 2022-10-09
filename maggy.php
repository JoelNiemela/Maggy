#!/usr/bin/env php
<?php

require 'Database.php';

function help() {
	echo "List of commands:\n";
	echo "\thelp — show this message\n";
}

function setup() {
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

function maggy_segment(string $segment, array $args, &$output_segment, &$output, $db_name): void {
	switch ($segment) {
		case 'Up':
			$output_segment = 'up';
			break;
		case 'Down':
			$output_segment = 'down';
			break;
		case 'Version':
			if (count($args) < 2) {
				echo "Error: Expected two arguments (version, description). Type `maggy help syntax` for more information.\n";
				break;
			}

			$version = $args[0];
			$description = $args[1];

			$version_global = <<<SQL
			USE $db_name;
			SQL;

			$version_up = <<<SQL
			INSERT INTO maggy_db_update (version, description)
			VALUES (
				$version,
				$description
			);\n
			SQL;

			$version_down = <<<SQL
			DELETE FROM maggy_db_update WHERE version=$version;\n
			SQL;

			$output['global'] .= $version_global;
			$output['up']     .= $version_up;
			$output['down']   .= $version_down;
			break;
		default:
			echo "Error: Unknown Maggy segment `--@$segment`. Type `maggy help syntax` for more information.\n";
			break;
	}
}

function maggy_attribute(string $attribute, array $args) {
	switch ($attribute) {
		default:
			echo "Error: Unknown Maggy attribute `--#$attribute`. Type `maggy help syntax` for more information.\n";
			break;
	}
}

function maggy_macro(string $macro, array $args, &$output): void {
	switch ($macro) {
		case 'Maggy':
			maggy_macro('MaggyUp', $args, $output);
			maggy_macro('MaggyDown', $args, $output);
			break;
		case 'MaggyUp':
			$maggy_up = <<<SQL
			CREATE TABLE IF NOT EXISTS maggy_db_update (
				version INT NOT NULL,
				description VARCHAR(255) NOT NULL,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
			);\n
			SQL;

			$output['up'] = $maggy_up . $output['up'];
			break;
		case 'MaggyDown':
			$maggy_down = <<<SQL
			DROP TABLE maggy_db_update;\n
			SQL;

			$output['down'] .= $maggy_down;
			break;
		default:
			echo "Error: Unknown Maggy macro `--!$macro`. Type `maggy help syntax` for more information.\n";
			break;
	}
}

function parse_args(string $args, string $head): array {
	if (preg_match('/^(\s(?:"[^"]*"|\w+))*\s*$/', $args)) {
		preg_match_all('/"[^"]*"|\w+/', $args, $matches);
		return $matches[0];
	} else {
		echo "Syntax Error: Invalid argument list in `$head$args`. Type `maggy help syntax` for more information.\n";
		return [];
	}
}

function parse_migration(string $migration_path, string $db_name): array {
	$lines = file($migration_path);
	$segment = 'global';
	$output = ['global' => '', 'up' => '', 'down' => ''];
	$attributes = [];
	foreach ($lines as $line) {
		$args = [];
		if (preg_match('/--@(?<segment>\w+)(?<args>.*)$/', $line, $args)) {
			$segment_args = parse_args($args['args'], "--@{$args['segment']}");
			maggy_segment($args['segment'], $segment_args, $segment, $output, $db_name);
		} elseif (preg_match('/--#(?<attribute>\w+)(?<args>.*)$/', $line, $args)) {
			$attribute_args = parse_args($args['args'], "--#{$args['attribute']}");
			$attributes[] = maggy_attribute($args['attribute'], $attribute_args);
		} elseif (preg_match('/--!(?<macro>\w+)(?<args>.*)$/', $line, $args)) {
			$macro_args = parse_args($args['args'], "--!{$args['macro']}");
			maggy_macro($args['macro'], $macro_args, $output);
		} else {
			$output[$segment] .= $line;
			$attributes = [];
		}
	}

	return $output;
}

function get_migration_path(int $version) {
	$dir = glob("./migration/*");
	$files = array_values(
		array_filter(
			$dir,
			fn($f) =>
				preg_match("/patch_$version(_.*?)?\.sql\.maggy$/", $f)
		)
	);

	if (count($files) != 1) {
		if (count($files) == 0) {
			echo "Nothing to migrate; already at version $version.\n";
		} else {
			echo "Error: Multiple files for migration version $version:\n";
			foreach ($files as $file) {
				echo "     | $file\n";
			}
		}

		die;
	}

	return $files[0];
}

function cull_diff(string $diff): string {
	// remove conditional-execution tokens.
	return preg_replace("~(?<=^|\n)(\+|-) \W*/\*.*?\*/\W*;.*\n?~", "", $diff);
}

function parse_diff(string $diff, string $diff_down): array {
	/**
	 * Takes two argument: $diff_raw and $diff_down
	 *
	 * $diff: The diff between before and after running the test.
	 * $diff_down: The diff between before and after running the
	 * rollback part of the test.
	 */

	$lines = explode("\n", $diff);

	$hints = [];
	foreach ($lines as $line) {
		if (preg_match("/^\+ CREATE TABLE `(?<name>[^`]+)`/", $line, $matches)) {
			$name = $matches['name'];
			if (preg_match("/(^|\n)\+ CREATE TABLE `$name`/", $diff_down)) {
				// If the table was created in the --@Down segment:
				$hints[] = <<<STR
				New table `$name` was created in --@Down.
				STR;
			} else {
				// If the table was created in the --@Up segment:
				$hints[] = <<<STR
				Table `$name` was created in --@Up, but not droped in --@Down.
				STR;
			}
		} elseif (preg_match("/^- CREATE TABLE `(?<name>[^`]+)`/", $line, $matches)) {
			$name = $matches['name'];
			if (preg_match("/(^|\n)- CREATE TABLE `$name`/", $diff_down)) {
				// If the table was dropped in the --@Down segment:
				$hints[] = <<<STR
				Table `$name` created before migration was droped in --@Down.
				STR;
			} else {
				// If the table was dropped in the --@Up segment:
				$hints[] = <<<STR
				Dropped table `$name` was not re-created in --@Down.

					If this is a breaking change, try adding --#Breaking.
					Type `maggy help --#Breaking` for more informataion.
				STR;
			}
		}
	}

	return $hints;
}

function migrate(Database $database, bool $view = false) {
	$version = $database->get_version();

	$db_name = $database->config['db_name'];
	$migration = parse_migration(get_migration_path($version), $db_name);

	$sql = $migration['global'] . $migration['up'];

	if ($view) return $sql;

	$config = $database->config;

	shell_exec("echo ".escapeshellarg($sql)." | mysql --user=\"{$config['user']}\" --database=\"{$config['db_name']}\"");
}

function rollback(Database $database, bool $view = false) {
	$version = $database->get_version();

	if ($version == 0) {
		echo "Can't rollback: already at earliest version.\n";
		return;
	}

	$db_name = $database->config['db_name'];
	$migration = parse_migration(get_migration_path($version - 1), $db_name);

	$sql = $migration['global'] . $migration['down'];

	if ($view) return $sql;

	$config = $database->config;

	shell_exec("echo ".escapeshellarg($sql)." | mysql --user=\"{$config['user']}\" --database=\"{$config['db_name']}\"");
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
