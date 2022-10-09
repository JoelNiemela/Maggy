<?php

class Database {
	public $sql;
	public $config;

	public function __construct($config) {
		$this->config = $config;

		$this->sql = new mysqli(
			$config['host'],
			$config['user'],
			$config['password'],
			$config['db_name']
		);
	}

    public static function db(): Database {
        return new Database(Database::load_db_config());
    }

    public static function test_db(): Database {
        $database = Database::db();

        $db_dump = $database->dump_db_all();

        $test_database = new Database(Database::load_test_db_config());
        $config = $test_database->config;

        $test_database->sql->multi_query("DROP DATABASE {$config['db_name']}; CREATE DATABASE {$config['db_name']};");
        do {
            $test_database->sql->store_result();
        } while ($test_database->sql->next_result());

        shell_exec("echo ".escapeshellarg($db_dump)." | mysql --user=\"{$config['user']}\" --database=\"{$config['db_name']}\"");

        return $test_database;
    }

    public function get_version(): int {
        $db_name = $this->config['db_name'];

        // Temporarily turn of error reporting, and use return code to check if table exists.
        mysqli_report(MYSQLI_REPORT_OFF);
        $result = $this->sql->query("SELECT * FROM $db_name.maggy_db_update ORDER BY version DESC LIMIT 1;");
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        if ($result === false) return 0;

        $update = mysqli_fetch_assoc($result);

        if ($update == null) return 0;

        return $update['version'];
    }

    public function dump_db_all() {
        $config = $this->config;
        $password = $config['password'] != '' ? "-p={$config['password']}" : '';
        return shell_exec("mysqldump --routines --events --compact -h {$config['host']} -u {$config['user']} $password {$config['db_name']}");
    }

    public function dump_db_definitions() {
        $config = $this->config;
        $password = $config['password'] != '' ? "-p={$config['password']}" : '';
        return shell_exec("mysqldump --no-data --routines --events --compact -h {$config['host']} -u {$config['user']} $password {$config['db_name']}");
    }

    public function dump_db_data() {
        $config = $this->config;
        $password = $config['password'] != '' ? "-p={$config['password']}" : '';
        return shell_exec("mysqldump --no-create-info --compact -h {$config['host']} -u {$config['user']} $password {$config['db_name']}");
    }

    private static function load_db_config(): array {
        $config = parse_ini_file('./config.ini', true);
        if (isset($config['config_link'])) {
            return parse_ini_file($config['config_link']['src']);
        } elseif (isset($config['dbconfig'])) {
            return $config['dbconfig'];
        } else {
            die("Error in config.ini: 'config_link' or 'dbconfig' segment required.\n");
        }
    }

    private static function load_test_db_config(): array {
        $config = Database::load_db_config();
        $config['db_name'] = 'Maggy'.$config['db_name'];

        return $config;
    }
}
