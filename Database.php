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
}
