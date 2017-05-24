<?php

namespace TheCodingMachine\Tdbm\GraphQL;


use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use TheCodingMachine\TDBM\Configuration;
use TheCodingMachine\TDBM\TDBMService;
use TheCodingMachine\TDBM\Utils\DefaultNamingStrategy;
use PHPUnit\Framework\TestCase;
use Youshido\GraphQL\Type\Scalar\StringType;

class GraphQLTypeGeneratorTest extends TestCase
{
    private static function getAdminConnectionParams(): array {
        return array(
            'user' => $GLOBALS['db_username'],
            'password' => $GLOBALS['db_password'],
            'host' => $GLOBALS['db_host'],
            'port' => $GLOBALS['db_port'],
            'driver' => $GLOBALS['db_driver'],
        );
    }

    private static function getConnectionParams(): array {
        $adminParams = self::getAdminConnectionParams();
        $adminParams['dbname'] = $GLOBALS['db_name'];
        return $adminParams;
    }

    public static function setUpBeforeClass()
    {
        $config = new \Doctrine\DBAL\Configuration();

        $adminConn = DriverManager::getConnection(self::getAdminConnectionParams(), $config);
        $adminConn->getSchemaManager()->dropAndCreateDatabase($GLOBALS['db_name']);

        self::loadSqlFile($adminConn, __DIR__.'/sql/graphqlunittest.sql');
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

    public function testGenerate()
    {
        $config = new \Doctrine\DBAL\Configuration();
        $dbConnection = DriverManager::getConnection(self::getConnectionParams(), $config);
        $tdbmService = self::getTDBMService($dbConnection);
        $tdbmService->generateAllDaosAndBeans();

        $this->assertFileExists(__DIR__.'/../src/Tests/GraphQL/UserType.php');
        $this->assertFileExists(__DIR__.'/../src/Tests/GraphQL/Generated/AbstractUserType.php');
    }
}
