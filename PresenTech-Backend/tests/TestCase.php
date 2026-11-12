<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Jangan pernah menjalankan test terhadap database aplikasi.
     *
     * phpunit.xml sudah memaksa SQLite in-memory. Namun bila
     * bootstrap/cache/config.php ada, Laravel memakai konfigurasi cache itu
     * dan mengabaikan env test — test bisa berjalan di MySQL dan menghapus
     * data (RefreshDatabase). Periksa sebelum aplikasi di-boot.
     */
    protected function setUp(): void
    {
        $configCache = dirname(__DIR__).'/bootstrap/cache/config.php';

        if (is_file($configCache)) {
            throw new RuntimeException(
                'Konfigurasi sedang di-cache (bootstrap/cache/config.php). '.
                'Jalankan "php artisan config:clear" sebelum test agar test tidak memakai database aplikasi.'
            );
        }

        $connection = $_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? getenv('DB_CONNECTION');
        $database = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE');

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                'Test harus memakai SQLite in-memory. Periksa phpunit.xml dan jalankan "php artisan config:clear".'
            );
        }

        parent::setUp();

        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException(
                'Test harus memakai SQLite in-memory. Periksa phpunit.xml dan jalankan "php artisan config:clear".'
            );
        }
    }
}
