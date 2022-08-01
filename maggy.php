#!/usr/bin/env php
<?php

if ($argc < 2) {
	die("Expected a command. Type `maggy help` for more information.\n");
}

function db_config(): array {
	$config = parse_ini_file('./config.ini', true);
	if (isset($config['config_link'])) {
		return parse_ini_file($config['config_link']['src']);
	} elseif (isset($config['dbconfig'])) {
		return $config['dbconfig'];
	} else {
		die("Error in config.ini: 'config_link' or 'dbconfig' segment required.\n");
	}
}

function test_db_config(): array {
	$config = db_config();
	$config['db_name'] = 'Maggy'.$config['db_name'];

	return $config;
}

$testing = false;
function load_config(): array {
	global $testing;

	if ($testing) {
		return test_db_config();
	} else {
		return db_config();
	}
}

$database;
function connect_database($config) {
	global $database;

	$database = new mysqli(
		$config['host'],
		$config['user'],
		$config['password'],
		$config['db_name']
	);
}

function help() {
	echo "List of commands:\n";
	echo "\thelp — show this message\n";
}

function maggy_segment(string $segment, array $args, &$output_segment, &$output): void {
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

			$db_config = load_config();

			$version = $args[0];
			$description = $args[1];

			$version_global = <<<SQL
			USE {$db_config['db_name']};
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
	if (preg_match('/^(\s(?:"[^"]+"|\w+))*\s*$/', $args)) {
		$matches;
		preg_match_all('/"[^"]+"|\w+/', $args, $matches);
		return $matches[0];
	} else {
		echo "Syntax Error: Invalid argument list in `$head$args`. Type `maggy help syntax` for more information.\n";
		return [];
	}
}

function parse_migration(string $migration_path): array {
	$lines = file($migration_path);
	$segment = 'global';
	$output = ['global' => '', 'up' => '', 'down' => ''];
	$attributes = [];
	foreach ($lines as $line) {
		$args = [];
		if (preg_match('/--@(?<segment>\w+)(?<args>.*)$/', $line, $args)) {
			$segment_args = parse_args($args['args'], "--@{$args['segment']}");
			maggy_segment($args['segment'], $segment_args, $segment, $output);
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
	$files = glob("./migration/patch_{$version}_*.sql.maggy");
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

function get_version(): int {
	global $database;

	connect_database(load_config());

	// Temporarily turn of error reporting, and use return code to check if table exists.
	mysqli_report(MYSQLI_REPORT_OFF);
	$result = $database->query("SELECT * FROM maggy_db_update ORDER BY version DESC LIMIT 1;");
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

	if ($result === false) return 0;

	$update = mysqli_fetch_assoc($result);

	if ($update == null) return 0;

	return $update['version'];
}

$command = $argv[1];
$args = array_slice($argv, 2);

switch ($command) {
	case 'help':
		help();
		break;
	case 'setup':
		$has_config = file_exists('./config.ini');
		$has_migrations = file_exists('./migration');

		if ($has_config && $has_migrations) {
			echo "Maggy is already active.\n";
			break;
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

		break;
	case 'db:version':
		$testing = true;

		echo get_version()."\n";
		break;
	case 'test':
		$testing = true;

		$version = get_version();

		$migration = parse_migration(get_migration_path($version));
		print_r($migration);
		break;
	case 'migrate':
		$testing = true;
		$view = ($args[0] ?? '') == 'view';

		$version = get_version();

		$migration = parse_migration(get_migration_path($version));

		$sql = $migration['global'] . $migration['up'];

		if ($view) {
			echo $sql;
		} else {
			$config = load_config();
			shell_exec("echo \"".addslashes($sql)."\" | mysql --user=\"{$config['user']}\" --database=\"{$config['db_name']}\"");
		}
		break;
	case 'rollback':
		$testing = true;
		$view = ($args[0] ?? '') == 'view';

		$version = get_version();

		if ($version == 0) {
			echo "Can't rollback: already at earliest version.\n";
			break;
		}

		$migration = parse_migration(get_migration_path($version - 1));

		$sql = $migration['global'] . $migration['down'];

		if ($view) {
			echo $sql;
		} else {
			$config = load_config();
			shell_exec("echo \"".addslashes($sql)."\" | mysql --user=\"{$config['user']}\" --database=\"{$config['db_name']}\"");
		}	
		break;
	default:
		die("Unknown command `$command`. Type `maggy help` for more information.\n");
		break;
}
