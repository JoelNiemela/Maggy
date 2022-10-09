<?php

class MaggyParser {
    private string $migration_path;
    private string $db_name;
    private array $segments;
    private string $segment;

    public function __construct(string $migration_path, string $db_name) {
        $this->migration_path = $migration_path;
        $this->db_name = $db_name;
        $this->segments = [];
    }

    private function maggy_segment(string $segment, array $args): void {
        switch ($segment) {
            case 'Up':
                $this->segment = 'up';
                break;
            case 'Down':
                $this->segment = 'down';
                break;
            case 'Version':
                if (count($args) < 2) {
                    echo "Error: Expected two arguments (version, description). Type `maggy help syntax` for more information.\n";
                    break;
                }

                $version = $args[0];
                $description = $args[1];

                $version_global = <<<SQL
                USE {$this->db_name};
                SQL;

                $version_up = <<<SQL
                INSERT INTO maggy_db_update (version, description)
                VALUES (
                    {$version},
                    {$description}
                );\n
                SQL;

                $version_down = <<<SQL
                DELETE FROM maggy_db_update WHERE version={$version};\n
                SQL;

                $this->segments['global'] .= $version_global;
                $this->segments['up']     .= $version_up;
                $this->segments['down']   .= $version_down;
                break;
            default:
                echo "Error: Unknown Maggy segment `--@$segment`. Type `maggy help syntax` for more information.\n";
                break;
        }
    }

    private function maggy_attribute(string $attribute, array $args): ?string {
        switch ($attribute) {
            default:
                echo "Error: Unknown Maggy attribute `--#$attribute`. Type `maggy help syntax` for more information.\n";
                break;
        }

        return null;
    }

    private function maggy_macro(string $macro, array $args): void {
        switch ($macro) {
            case 'Maggy':
                $this->maggy_macro('MaggyUp', $args);
                $this->maggy_macro('MaggyDown', $args);
                break;
            case 'MaggyUp':
                $maggy_up = <<<SQL
                CREATE TABLE IF NOT EXISTS maggy_db_update (
                    version INT NOT NULL,
                    description VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );\n
                SQL;

                $this->segments['up'] = $maggy_up . $this->segments['up'];
                break;
            case 'MaggyDown':
                $maggy_down = <<<SQL
                DROP TABLE maggy_db_update;\n
                SQL;

                $this->segments['down'] .= $maggy_down;
                break;
            default:
                echo "Error: Unknown Maggy macro `--!$macro`. Type `maggy help syntax` for more information.\n";
                break;
        }
    }

    private function parse_args(string $args, string $head): array {
        if (preg_match('/^(\s(?:"[^"]*"|\w+))*\s*$/', $args)) {
            preg_match_all('/"[^"]*"|\w+/', $args, $matches);
            return $matches[0];
        } else {
            echo "Syntax Error: Invalid argument list in `$head$args`. Type `maggy help syntax` for more information.\n";
            return [];
        }
    }

    public function parse_migration(): array {
        $lines = file($this->migration_path);
        $this->segment = 'global';
        $this->segments = ['global' => '', 'up' => '', 'down' => ''];
        $attributes = [];
        foreach ($lines as $line) {
            $args = [];
            if (preg_match('/--@(?<segment>\w+)(?<args>.*)$/', $line, $args)) {
                $segment_args = $this->parse_args($args['args'], "--@{$args['segment']}");
                $this->maggy_segment($args['segment'], $segment_args);
            } elseif (preg_match('/--#(?<attribute>\w+)(?<args>.*)$/', $line, $args)) {
                $attribute_args = $this->parse_args($args['args'], "--#{$args['attribute']}");
                $attributes[] = $this->maggy_attribute($args['attribute'], $attribute_args);
            } elseif (preg_match('/--!(?<macro>\w+)(?<args>.*)$/', $line, $args)) {
                $macro_args = $this->parse_args($args['args'], "--!{$args['macro']}");
                $this->maggy_macro($args['macro'], $macro_args);
            } else {
                $this->segments[$this->segment] .= $line;
                $attributes = [];
            }
        }

        return $this->segments;
    }
}
