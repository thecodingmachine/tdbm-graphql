<?php

namespace TheCodingMachine\Tdbm\GraphQL;


use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Mouf\Database\TDBM\Configuration;
use Mouf\Database\TDBM\TDBMService;
use Mouf\Database\TDBM\Utils\DefaultNamingStrategy;
use PHPUnit\Framework\TestCase;

class GraphQLTypeGeneratorTest extends TestCase
{
    public static function setUpBeforeClass()
    {
        $config = new \Doctrine\DBAL\Configuration();

        $connectionParams = array(
            'user' => $GLOBALS['db_username'],
            'password' => $GLOBALS['db_password'],
            'host' => $GLOBALS['db_host'],
            'port' => $GLOBALS['db_port'],
            'driver' => $GLOBALS['db_driver'],
        );

        $adminConn = DriverManager::getConnection($connectionParams, $config);
        $adminConn->getSchemaManager()->dropAndCreateDatabase($GLOBALS['db_name']);

        $connectionParams['dbname'] = $GLOBALS['db_name'];

        $dbConnection = DriverManager::getConnection($connectionParams, $config);

        self::loadSqlFile($dbConnection, __DIR__.'/sql/graphqlunittest.sql');

        $tdbmService = self::getTDBMService($dbConnection);

        $tdbmService->generateAllDaosAndBeans();
    }

    protected static function loadSqlFile(Connection $connection, $sqlFile)
    {
        $sql = file_get_contents($sqlFile);

        $stmt = $connection->prepare($sql);
        $stmt->execute();
    }

    protected static function getTDBMService(Connection $connection) : TDBMService
    {
        $configuration = new Configuration('TheCodingMachine\\Tdbm\\GraphQL\\Tests\\Beans', 'TheCodingMachine\\Tdbm\\GraphQL\\Tests\\DAOs', $connection, new DefaultNamingStrategy(), new ArrayCache(),null, null, [
            new GraphQLTypeGenerator('TheCodingMachine\\Tdbm\\GraphQL\\Tests\\GraphQL')
        ]);

        return new TDBMService($configuration);
    }

    public function testFilesCreated()
    {

    }
}
