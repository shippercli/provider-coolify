<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderCoolify\CoolifyProvider;

final class DatabaseConnectionUrlTest extends TestCase
{
    public function testUrlComponentsAreDecodedExactlyOnceForApplicationVariables(): void
    {
        $provider = new CoolifyProvider;
        $merge = new \ReflectionMethod($provider, 'mergeDatabaseEnvironment');
        foreach (['mysql', 'mariadb', 'postgres'] as $type) {
            foreach (['sales reports', 'literal%2Fname', 'plus+name', 'données'] as $database) {
                $environment = [];
                $user = 'user+@team';
                $password = 'literal%+:/@';
                $url = 'mysql://'.rawurlencode($user).':'.rawurlencode($password).'@database.internal:3307/'.rawurlencode($database).'?sslmode=require';
                $arguments = [&$environment, $type, 'main', ['internal_db_url' => $url], null, null];
                $merge->invokeArgs($provider, $arguments);
                self::assertSame($database, $environment['DB_DATABASE']);
                self::assertSame($database, $environment['SHIPPER_DB_MAIN_DATABASE']);
                self::assertSame($user, $environment['DB_USERNAME']);
                self::assertSame($password, $environment['DB_PASSWORD']);
                self::assertSame('database.internal', $environment['DB_HOST']);
                self::assertSame('3307', $environment['DB_PORT']);
                self::assertSame($url, $environment['SHIPPER_DB_MAIN_URL']);
            }
        }
    }

    public function testDirectApiFieldsAreLiteralRatherThanUrlDecoded(): void
    {
        $provider = new CoolifyProvider;
        $environment = [];
        $arguments = [&$environment, 'mysql', 'main', [
            'mysql_database' => 'literal%20name', 'mysql_user' => 'user%40team',
            'mysql_password' => 'secret%2Bvalue', 'internal_db_host' => 'database.internal',
        ], null, null];
        (new \ReflectionMethod($provider, 'mergeDatabaseEnvironment'))->invokeArgs($provider, $arguments);
        self::assertSame('literal%20name', $environment['DB_DATABASE']);
        self::assertSame('user%40team', $environment['DB_USERNAME']);
        self::assertSame('secret%2Bvalue', $environment['DB_PASSWORD']);
    }
    public function testFullUrlsPreserveMongoTopologyAndQueryOptions(): void
    {
        foreach (['mongodb+srv://user:secret@cluster.internal/main?authSource=admin&tls=true',
            'mongodb://user:secret@first.internal:27017,second.internal:27018/main?replicaSet=rs0&authSource=admin'] as $url) {
            $environment = [];
            $args = [&$environment, 'mongodb', 'main', ['internal_db_url' => $url], null, null];
            (new \ReflectionMethod(new CoolifyProvider, 'mergeDatabaseEnvironment'))->invokeArgs(new CoolifyProvider, $args);
            self::assertSame($url, $environment['SHIPPER_DB_MAIN_URL'] ?? null);
        }
    }

    public function testFullUrlUsesUpdatedCredentialsWithoutLosingOptions(): void
    {
        $environment = [];
        $args = [&$environment, 'mysql', 'main',
            ['internal_db_url' => 'mysql://known-user:old-secret@db.internal:3307/old?sslmode=require'], null, null,
            ['mysql_password' => 'updated:@secret', 'mysql_database' => 'sales reports']];
        $provider = new CoolifyProvider;
        (new \ReflectionMethod($provider, 'mergeDatabaseEnvironment'))->invokeArgs($provider, $args);
        self::assertSame('mysql://known-user:updated%3A%40secret@db.internal:3307/sales%20reports?sslmode=require', $environment['SHIPPER_DB_MAIN_URL'] ?? null);
        self::assertStringNotContainsString('old-secret', $environment['SHIPPER_DB_MAIN_URL']);
    }

    public function testEmptyPasswordUpdatesRemainExplicitInFullUrls(): void
    {
        $environment = [];
        $args = [&$environment, 'mysql', 'main', ['internal_db_url' => 'mysql://user:old-secret@db.internal/main?tls=true'], null, null,
            ['mysql_password' => '']];
        $provider = new CoolifyProvider;
        (new \ReflectionMethod($provider, 'mergeDatabaseEnvironment'))->invokeArgs($provider, $args);
        self::assertSame('mysql://user:@db.internal/main?tls=true', $environment['SHIPPER_DB_MAIN_URL']);
        self::assertSame('', $environment['SHIPPER_DB_MAIN_PASSWORD']);
    }

    public function testMalformedUrlDiagnosticsDoNotEchoCredentials(): void
    {
        $environment = [];
        $args = [&$environment, 'mysql', 'main', ['internal_db_url' => 'synthetic-secret'], null, null];
        $provider = new CoolifyProvider;
        $failure = null;
        try { (new \ReflectionMethod($provider, 'mergeDatabaseEnvironment'))->invokeArgs($provider, $args); }
        catch (\RuntimeException $exception) { $failure = $exception; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('Coolify returned an invalid database connection URL.', $failure->getMessage());
    }

}
