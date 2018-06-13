<?php

namespace TheCodingMachine\Tdbm\GraphQL;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use TheCodingMachine\TDBM\Configuration;
use TheCodingMachine\Tdbm\GraphQL\Fixtures\TestSchema;
use TheCodingMachine\Tdbm\GraphQL\Registry\EmptyContainer;
use TheCodingMachine\Tdbm\GraphQL\Registry\Registry;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\Country;
use TheCodingMachine\Tdbm\GraphQL\Tests\DAOs\CountryDao;
use TheCodingMachine\Tdbm\GraphQL\Tests\DAOs\UserDao;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\CountryType;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\Generated\AbstractCountryType;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\Generated\AbstractUserType;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\TdbmGraphQLTypeMapper;
use TheCodingMachine\TDBM\TDBMService;
use TheCodingMachine\TDBM\Utils\DefaultNamingStrategy as TdbmDefaultNamingStrategy;
use PHPUnit\Framework\TestCase;
use Youshido\GraphQL\Execution\Context\ExecutionContext;
use Youshido\GraphQL\Execution\Processor;
use Youshido\GraphQL\Execution\ResolveInfo;
use Youshido\GraphQL\Schema\Schema;
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
        $this->recursiveDelete(__DIR__.'/../src/Tests/GraphQL/');

        $tdbmService = self::getTDBMService();
        $tdbmService->generateAllDaosAndBeans();

        $this->assertFileExists(__DIR__.'/../src/Tests/GraphQL/UserType.php');
        $this->assertFileExists(__DIR__.'/../src/Tests/GraphQL/Generated/AbstractUserType.php');
        $this->assertFileExists(__DIR__.'/../src/Tests/GraphQL/TdbmGraphQLTypeMapper.php');

        $this->assertTrue(class_exists(AbstractCountryType::class));
        $abstractCountryType = new \ReflectionClass(AbstractCountryType::class);
        $this->assertNotNull($abstractCountryType->getMethod('getUsersField'));
        $abstractUserType = new \ReflectionClass(AbstractUserType::class);
        $this->assertNotNull($abstractUserType->getMethod('getRolesField'));

        $tdbmGraphQLTypeMapper = new \ReflectionClass(TdbmGraphQLTypeMapper::class);
        $this->assertNotNull($tdbmGraphQLTypeMapper->getMethod('mapClassToType'));
    }

    /**
     * @depends testGenerate
     */
    public function testQuery()
    {
        $tdbmService = self::getTDBMService();
        $userDao = new UserDao($tdbmService);
        $registry = TestRegistryFactory::build();
        $processor = new Processor(new TestSchema($registry, $userDao));

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

    /**
     * Delete a file or recursively delete a directory.
     *
     * @param string $str Path to file or directory
     * @return bool
     */
    private function recursiveDelete(string $str) : bool
    {
        if (is_file($str)) {
            return @unlink($str);
        } elseif (is_dir($str)) {
            $scan = glob(rtrim($str, '/') . '/*');
            foreach ($scan as $index => $path) {
                $this->recursiveDelete($path);
            }

            return @rmdir($str);
        }
        return false;
    }

    public function testResultIteratorType()
    {
        $type = new ResultIteratorType(new CountryType(TestRegistryFactory::build()));

        $tdbmService = self::getTDBMService();
        $countryDao = new CountryDao($tdbmService);

        $countries = $countryDao->findAll();

        $countField = $type->getField('count');
        $resolveInfo = $this->getMockBuilder(ResolveInfo::class)->disableOriginalConstructor()->getMock();
        $this->assertEquals(3, $countField->resolve($countries, [], $resolveInfo));

        $itemsField = $type->getField('items');
        $result = $itemsField->resolve($countries, [], $resolveInfo);
        $this->assertCount(3, $result);
        $this->assertInstanceOf(Country::class, $result[0]);

        $result = $itemsField->resolve($countries, ['order'=>'label'], $resolveInfo);
        $this->assertEquals('Jamaica', $result[1]->getLabel());

        $result = $itemsField->resolve($countries, ['offset'=>1, 'limit'=>1], $resolveInfo);
        $this->assertCount(1, $result);

        $this->expectException(GraphQLException::class);
        $result = $itemsField->resolve($countries, ['offset'=>1], $resolveInfo);
    }
}
