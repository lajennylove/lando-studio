<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MysqlDumpTablePrefixDetector;
use PHPUnit\Framework\TestCase;

class MysqlDumpTablePrefixDetectorTest extends TestCase
{
    public function test_detects_wp_prefix_from_gzipped_dump(): void
    {
        $sql = "CREATE TABLE `wp_options` (\n  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT\n);";
        $path = $this->tempGzip($sql);
        $this->assertSame('wp_', MysqlDumpTablePrefixDetector::fromGzipFile($path));
        unlink($path);
    }

    public function test_detects_custom_prefix(): void
    {
        // A real dump has a header before the first table; the detector rejects files under 100 bytes.
        $sql = "-- MariaDB dump 10.19  Distrib 10.11.11-MariaDB\n"
            ."-- Host: localhost    Database: wordpress\n"
            ."-- ------------------------------------------------------\n\n"
            ."DROP TABLE IF EXISTS `acme_options`;\n"
            ."CREATE TABLE `acme_options` (\n"
            ."  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
            ."  `option_name` varchar(191) NOT NULL DEFAULT '',\n"
            ."  PRIMARY KEY (`option_id`)\n"
            .") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
        $path = $this->tempGzip($sql);
        $this->assertSame('acme_', MysqlDumpTablePrefixDetector::fromGzipFile($path));
        unlink($path);
    }

    public function test_unquoted_create_table(): void
    {
        $sql = 'CREATE TABLE wp_options (option_id bigint);';
        $path = $this->tempGzip($sql);
        $this->assertSame('wp_', MysqlDumpTablePrefixDetector::fromGzipFile($path));
        unlink($path);
    }

    public function test_backticked_database_qualified_table(): void
    {
        $sql = "CREATE TABLE `wordpress`.`wp_options` (\n  `option_id` bigint(20)\n);";
        $path = $this->tempGzip($sql);
        $this->assertSame('wp_', MysqlDumpTablePrefixDetector::fromGzipFile($path));
        unlink($path);
    }

    public function test_missing_file_defaults_to_wp(): void
    {
        $this->assertSame('wp_', MysqlDumpTablePrefixDetector::fromGzipFile('/nonexistent/dump.sql.gz'));
    }

    private function tempGzip(string $sql): string
    {
        $gz = gzencode($sql);
        $path = tempnam(sys_get_temp_dir(), 'ld_');
        if ($path === false) {
            $this->fail('tempnam failed');
        }
        file_put_contents($path, $gz);

        return $path;
    }
}
