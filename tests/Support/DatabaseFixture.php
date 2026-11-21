<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests\Support;

use PDO;
use Prime\Auth\Auth;
use Prime\Database\Connection;
use Prime\Kernel\Application;
use Prime\Tests\Support\ApiTestFixture;
use RuntimeException;

/** Run the CMS API fixture on isolated MySQL databases in CI, and SQLite for local fallback. */
trait DatabaseFixture
{
    use ApiTestFixture {
        createApp as private createSqliteApp;
    }

    /** @var list<array{server: PDO, name: string}> */
    private array $mysqlDatabases = [];

    protected function createApp(?string $authenticateAs): Application
    {
        if (getenv('PB_TEST_MYSQL') !== '1') {
            return $this->createSqliteApp($authenticateAs);
        }

        // The CMS fixture initializes the application and seed data in an in-memory SQLite DB.
        // Copy that fixture into a fresh MySQL database before resolving any user services.
        $app = $this->createSqliteApp(null);
        $source = $this->db($app)->pdo();
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
        $server = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $username, $password, $options);
        $name = 'prime_builder_test_' . bin2hex(random_bytes(8));
        $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->mysqlDatabases[] = ['server' => $server, 'name' => $name];
        $mysql = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4', $username, $password, $options);
        $mysql->exec("SET time_zone = '+00:00'");
        $mysql->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,ONLY_FULL_GROUP_BY'");

        $tables = $source->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name LIKE 'pc_%' ORDER BY name")->fetchAll();
        foreach ($tables as $table) {
            $tableName = (string) $table['name'];
            if (preg_match('/^pc_[a-z_]+$/D', $tableName) !== 1) {
                throw new RuntimeException('Unexpected fixture table name');
            }
            $schema = str_replace(
                ['INTEGER PRIMARY KEY AUTOINCREMENT', '(key CHAR', ' value LONGTEXT', ' count INTEGER'],
                ['BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY', '(`key` CHAR', ' `value` LONGTEXT', ' `count` INTEGER'],
                (string) $table['sql'],
            );
            $mysql->exec($schema . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $rows = $source->query('SELECT * FROM `' . $tableName . '`')->fetchAll();
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $columnSql = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
                $parameters = implode(', ', array_fill(0, count($columns), '?'));
                $statement = $mysql->prepare('INSERT INTO `' . $tableName . '` (' . $columnSql . ') VALUES (' . $parameters . ')');
                $statement->execute(array_values($row));
            }
        }

        $app->container()->instance(Connection::class, new Connection(['prefix' => 'pc_'], pdo: $mysql));
        if ($authenticateAs !== null) {
            $app->get(Auth::class)->login($this->user($app, $authenticateAs === 'admin' ? 1 : 2));
        }
        return $app;
    }

    private function removeMysqlDatabases(): void
    {
        foreach ($this->mysqlDatabases as $database) {
            $database['server']->exec('DROP DATABASE `' . $database['name'] . '`');
        }
        $this->mysqlDatabases = [];
    }
}
