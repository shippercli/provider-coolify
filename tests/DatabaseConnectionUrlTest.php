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
}
