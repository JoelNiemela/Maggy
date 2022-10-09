<?php

function get_migration_path(int $version): string {
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
