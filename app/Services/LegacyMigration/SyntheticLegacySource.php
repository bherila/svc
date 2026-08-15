<?php

namespace App\Services\LegacyMigration;

use InvalidArgumentException;
use PDO;

final class SyntheticLegacySource
{
    /**
     * @return array{legacy_user_id: int, source_rows: int}
     */
    public function create(string $path): array
    {
        if ($path === ':memory:') {
            throw new InvalidArgumentException('Synthetic legacy sources require an SQLite file path, not :memory:.');
        }

        if ($path === '' || is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new InvalidArgumentException('Synthetic legacy sources require an exact, regular SQLite file path.');
        }

        if (file_exists($path) && filesize($path) > 0) {
            throw new InvalidArgumentException('Synthetic legacy source path must not contain an existing non-empty database.');
        }

        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE client_companies (id INTEGER PRIMARY KEY, company_name TEXT, slug TEXT, billing_email TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE client_company_user (id INTEGER PRIMARY KEY, client_company_id INTEGER, user_id INTEGER, role TEXT)');
        $pdo->exec('CREATE TABLE client_projects (id INTEGER PRIMARY KEY, client_company_id INTEGER, name TEXT, description TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE client_tasks (id INTEGER PRIMARY KEY, project_id INTEGER, name TEXT, description TEXT, completed_at TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec("INSERT INTO users VALUES (7, 'Synthetic User Example', 'synthetic.user@example.test', '2026-01-01', '2026-01-02')");
        $pdo->exec("INSERT INTO client_companies VALUES (11, 'Synthetic Example Business', 'synthetic-example-business', 'synthetic.billing@example.test', 1, '2026-01-01', '2026-01-02')");
        $pdo->exec("INSERT INTO client_company_user VALUES (12, 11, 7, 'client')");
        $pdo->exec("INSERT INTO client_projects VALUES (13, 11, 'Synthetic Project Example', 'Synthetic project description', '2026-01-03', '2026-01-04')");
        $pdo->exec("INSERT INTO client_tasks VALUES (14, 13, 'Synthetic Task Example', 'Synthetic task description', NULL, '2026-01-05', '2026-01-06')");
        $pdo->commit();

        return [
            'legacy_user_id' => 7,
            'source_rows' => 5,
        ];
    }
}
