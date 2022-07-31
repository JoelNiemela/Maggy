## Maggy — An SQL Migration Tool ##

Maggy is a tool written in PHP to facilitate the making and applying of migrations to SQL databases.

## Installation ##

In order to install Maggy:
- Clone this repository `git clone https://github.com/JoelNiemela/Maggy.git`
- Download an SQL server, such as `mariadb`
- Configure `config.ini` to connect to your database

## Maggy files ##

In Maggy, each migration is described by a maggy file. Maggy files consist of vanilla SQL along with Maggy macros that enables `maggy migrate` and `maggy rollback`.

Maggy files end with the `.sql.maggy` file extension;

An example Maggy file:
```sql
--@Version 42 "Add user table"

--@Up
CREATE TABLE IF NOT EXISTS user (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

--@Down
DROP TABLE user;
```

## Maggy Syntax ##

There are three types of Macros in Maggy:
- Segment macros (`--@Segment`):
  - Introduce new segments, such as `--@Up` and `--@Down`.
  - Declare the context of a file with the `--@Version` macro.
- Attribute macros (`--#Attribute`):
  - Modify the behaviour of SQL queries or other macros.
- Command macros (`--!Command`):
  - Enable Maggy for a database with the `--!Maggy` macro.
  - Shorthand for SQL queries.

Every Maggy file must start with the `--@Version` macro.


### Full list of macros ###
- `--@Version [version] [description]`
  - Declare migration version of a file, along with a short description describing the migration.
- `--@Up`
  - Initiates the `Up` segment; all subsequent lines (until the next segment macro) belong to the `Up` segment.
- `--@Down`
  - Initiates the `Down` segment; all subsequent lines (until the next segment macro) belong to the `Down` segment.
- `--!Maggy`
  - Enables Maggy support for the database. Yyour first migration for a database with Maggy should use this command.
