<?php

namespace TheCodingMachine\Tdbm\GraphQL;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use TheCodingMachine\TDBM\Configuration;
use TheCodingMachine\Tdbm\GraphQL\Fixtures\TestSchema;
use TheCodingMachine\Tdbm\GraphQL\Tests\DAOs\UserDao;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\Generated\AbstractCountryType;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\Generated\AbstractUserType;
use TheCodingMachine\TDBM\TDBMService;
use TheCodingMachine\TDBM\Utils\DefaultNamingStrategy as TdbmDefaultNamingStrategy;
use PHPUnit\Framework\TestCase;
use Youshido\GraphQL\Execution\Processor;
use Youshido\GraphQL\Type\Scalar\StringType;

class GraphQLTypeGeneratorTest extends TestCase
{
    private static function getAdminConnectionParams(): array
    {
        return array(
            'user' => $GLOBALS['db_username'],
            'password' => $GLOBALS['db_password'],
            'host' => $GLOBALS['db_host'],
            'port' => $GLOBALS['db_port'],
            'driver' => $GLOBALS['db_driver'],
        );
    }

    private static function getConnectionParams(): array
    {
        $adminParams = self::getAdminConnectionParams();
        $adminParams['dbname'] = $GLOBALS['db_name'];
        return $adminParams;
    }

    public static function setUpBeforeClass()
    {
        $config = new \Doctrine\DBAL\Configuration();

        $adminConn = DriverManager::getConnection(self::getAdminConnectionParams(), $config);
        $adminConn->getSchemaManager()->dropAndCreateDatabase($GLOBALS['db_name']);

        $conn = DriverManager::getConnection(self::getConnectionParams(), $config);

        self::loadSqlFile($conn, __DIR__.'/sql/graphqlunittest.sql');
    }

    protected static function loadSqlFile(Connection $connection, $sqlFile)
    {
        $sql = file_get_contents($sqlFile);

        $stmt = $connection->prepare($sql);
        $stmt->execute();
    }

    protected static function getTDBMService() : TDBMService
    {
        $config = new \Doctrine\DBAL\Configuration();
        $connection = DriverManager::getConnection(self::getConnectionParams(), $config);
        $configuration = new Configuration('TheCodingMachine\\Tdbm\\GraphQL\\Tests\\Beans', 'TheCodingMachine\\Tdbm\\GraphQL\\Tests\\DAOs', $connection, new TdbmDefaultNamingStrategy(), new ArrayCache(), null, null, [
            new GraphQLTypeGenerator('TheCodingMachine\\Tdbm\\GraphQL\\Tests\\GraphQL')
        ]);

        return new TDBMService($configuration);
    }

    public function testGenerate()
    {
        $tdbmService = self::getTDBMService();
        $tdbmService->generateAllDaosAndBeans();

        $this->assertFileExists(__DIR__.'/../src/Tests/GraphQL/UserType.php');
        $this->assertFileExists(__DIR__.'/../src/Tests/GraphQL/Generated/AbstractUserType.php');

        $this->assertTrue(class_exists(AbstractCountryType::class));
        $abstractCountryType = new \ReflectionClass(AbstractCountryType::class);
        $this->assertNotNull($abstractCountryType->getMethod('getUsersField'));
        $abstractUserType = new \ReflectionClass(AbstractUserType::class);
        $this->assertNotNull($abstractUserType->getMethod('getRolesField'));
    }

    /**
     * @depends testGenerate
     */
    public function testQuery()
    {
        $tdbmService = self::getTDBMService();
        $userDao = new UserDao($tdbmService);
        $processor = new Processor(new TestSchema($userDao));

        $introspectionQuery = <<<EOF
{
  __schema {
    queryType {
      name
    }
  }
}
EOF;


        $response = $processor->processPayload($introspectionQuery, [])->getResponseData();
        $this->assertTrue(isset($response['data']['__schema']['queryType']['name']));

        $introspectionQuery2 = <<<EOF
{
  __type(name: "User") {
    name
    kind
    fields {
      name
      type {
        name
        kind
      }
    }
  }
}
EOF;

        $response = $processor->processPayload($introspectionQuery2, [])->getResponseData();
        $this->assertSame('User', $response['data']['__type']['name']);
        $this->assertSame('OBJECT', $response['data']['__type']['kind']);
        $this->assertSame('id', $response['data']['__type']['fields'][0]['name']);
        $this->assertSame('ID', $response['data']['__type']['fields'][0]['type']['name']);
        $this->assertSame('SCALAR', $response['data']['__type']['fields'][0]['type']['kind']);

        $found = false;
        foreach ($response['data']['__type']['fields'] as $field) {
            if ($field['name'] === 'roles') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Failed asserting the roles field is found in user type.');

        $introspectionQuery3 = <<<EOF
{
  users {
    id,
    name,
    roles {
      name
    }
  }
}
EOF;
        $response = $processor->processPayload($introspectionQuery3, [])->getResponseData();
        $this->assertSame('John Smith', $response['data']['users'][0]['name']);
        $this->assertSame('Admins', $response['data']['users'][0]['roles'][0]['name']);
    }
}
