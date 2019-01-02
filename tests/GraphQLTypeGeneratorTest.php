<?php

namespace TheCodingMachine\Tdbm\GraphQL;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\SchemaConfig;
use Mouf\Picotainer\Picotainer;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Simple\NullCache;
use TheCodingMachine\GraphQL\Controllers\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationReader as DoctrineAnnotationReader;
use TheCodingMachine\GraphQL\Controllers\Containers\BasicAutoWiringContainer;
use TheCodingMachine\GraphQL\Controllers\FieldsBuilderFactory;
use TheCodingMachine\GraphQL\Controllers\Hydrators\FactoryHydrator;
use TheCodingMachine\GraphQL\Controllers\Hydrators\HydratorInterface;
use TheCodingMachine\GraphQL\Controllers\InputTypeGenerator;
use TheCodingMachine\GraphQL\Controllers\InputTypeUtils;
use TheCodingMachine\GraphQL\Controllers\Mappers\GlobTypeMapper;
use TheCodingMachine\GraphQL\Controllers\Mappers\RecursiveTypeMapper;
use TheCodingMachine\GraphQL\Controllers\Mappers\RecursiveTypeMapperInterface;
use TheCodingMachine\GraphQL\Controllers\Mappers\TypeMapperInterface;
use TheCodingMachine\GraphQL\Controllers\NamingStrategy;
use TheCodingMachine\GraphQL\Controllers\Reflection\CachedDocBlockFactory;
use TheCodingMachine\GraphQL\Controllers\Security\AuthenticationServiceInterface;
use TheCodingMachine\GraphQL\Controllers\Security\AuthorizationServiceInterface;
use TheCodingMachine\GraphQL\Controllers\Security\VoidAuthenticationService;
use TheCodingMachine\GraphQL\Controllers\Security\VoidAuthorizationService;
use TheCodingMachine\GraphQL\Controllers\TypeGenerator;
use TheCodingMachine\GraphQL\Controllers\Types\TypeResolver;
use TheCodingMachine\TDBM\Configuration;
use TheCodingMachine\Tdbm\GraphQL\Registry\EmptyContainer;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\Country;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\User;
use TheCodingMachine\Tdbm\GraphQL\Tests\DAOs\CountryDao;
use TheCodingMachine\Tdbm\GraphQL\Tests\DAOs\UserDao;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\CountryType;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\Generated\AbstractCountryType;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\Generated\AbstractUserType;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\TdbmGraphQLTypeMapper;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\UserType;
use TheCodingMachine\TDBM\TDBMService;
use TheCodingMachine\TDBM\Utils\DefaultNamingStrategy as TdbmDefaultNamingStrategy;
use PHPUnit\Framework\TestCase;
use Youshido\GraphQL\Execution\Context\ExecutionContext;
use Youshido\GraphQL\Execution\Processor;
use Youshido\GraphQL\Execution\ResolveInfo;
use GraphQL\Type\Schema;
use Youshido\GraphQL\Type\Scalar\StringType;

class GraphQLTypeGeneratorTest extends TestCase
{
    /**
     * @var ContainerInterface
     */
    private $mainContainer;

    public function setUp()
    {
        $this->mainContainer = new Picotainer([
            FieldsBuilderFactory::class => function(ContainerInterface $container) {
                return new FieldsBuilderFactory(
                    $container->get(AnnotationReader::class),
                    $container->get(HydratorInterface::class),
                    $container->get(AuthenticationServiceInterface::class),
                    $container->get(AuthorizationServiceInterface::class),
                    $container->get(TypeResolver::class),
                    $container->get(CachedDocBlockFactory::class)
                );
            },
            BasicAutoWiringContainer::class => function (ContainerInterface $container) {
                return new BasicAutoWiringContainer(new EmptyContainer());
            },
            AuthorizationServiceInterface::class => function (ContainerInterface $container) {
                return new VoidAuthorizationService();
            },
            AuthenticationServiceInterface::class => function (ContainerInterface $container) {
                return new VoidAuthenticationService();
            },
            RecursiveTypeMapperInterface::class => function(ContainerInterface $container) {
                return new RecursiveTypeMapper($container->get(TypeMapperInterface::class), $container->get(NamingStrategyInterface::class), new \Symfony\Component\Cache\Simple\ArrayCache());
            },
            TypeMapperInterface::class => function (ContainerInterface $container) {
                return new GlobTypeMapper('TheCodingMachine\\Tdbm\\GraphQL\\Tests\\GraphQL',
                    $container->get(TypeGenerator::class),
                    $container->get(InputTypeGenerator::class),
                    $container->get(InputTypeUtils::class),
                    $container->get(BasicAutoWiringContainer::class),
                    $container->get(AnnotationReader::class),
                    $container->get(NamingStrategyInterface::class),
                    new \Symfony\Component\Cache\Simple\ArrayCache()
                );
            },
            TypeGenerator::class => function(ContainerInterface $container) {
                return new TypeGenerator(
                    $container->get(AnnotationReader::class),
                    $container->get(FieldsBuilderFactory::class),
                    $container->get(NamingStrategyInterface::class)
                );
            },
            AnnotationReader::class => function (ContainerInterface $container) {
                return new AnnotationReader(new DoctrineAnnotationReader());
            },
            HydratorInterface::class => function(ContainerInterface $container) {
                return new FactoryHydrator();
            },
            InputTypeGenerator::class => function(ContainerInterface $container) {
                return new InputTypeGenerator(
                    $container->get(InputTypeUtils::class),
                    $container->get(FieldsBuilderFactory::class),
                    $container->get(HydratorInterface::class)
                );
            },
            InputTypeUtils::class => function(ContainerInterface $container) {
                return new InputTypeUtils(
                    $container->get(AnnotationReader::class),
                    $container->get(NamingStrategyInterface::class)
                );
            },
            TypeResolver::class => function(ContainerInterface $container) {
                return new TypeResolver();
            },
            CachedDocBlockFactory::class => function() {
                return new CachedDocBlockFactory(new \Symfony\Component\Cache\Simple\ArrayCache());
            },
            NamingStrategyInterface::class => function() {
                return new NamingStrategy();
            },
        ]);
    }


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
        $loader = require __DIR__.'/../vendor/autoload.php';
        AnnotationRegistry::registerLoader([$loader, 'loadClass']);

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
        //$container = new EmptyContainer();
        /*$typeMapper = new TdbmGraphQLTypeMapper();
        $registry = TestRegistryFactory::build($container, null, null, null, $typeMapper);
        $typeMapper->setContainer($registry);*/


        $queryType = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'users' => [
                    'type'    => Type::listOf($this->mainContainer->get(RecursiveTypeMapperInterface::class)->mapClassToType(User::class)),
                    'resolve' => function () use ($userDao) {
                        return $userDao->findAll();
                    }
                ]
            ]
        ]);


        $schema = new Schema([
            'query' => $queryType
        ]);

        $introspectionQuery = <<<EOF
{
  __schema {
    queryType {
      name
    }
  }
}
EOF;

        $response = GraphQL::executeQuery($schema, $introspectionQuery)->toArray();
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

        $response = GraphQL::executeQuery($schema, $introspectionQuery2)->toArray();
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
        $response = GraphQL::executeQuery($schema, $introspectionQuery3)->toArray();
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
        $type = new ResultIteratorType($this->mainContainer->get(RecursiveTypeMapperInterface::class)->mapClassToType(Country::class));

        $tdbmService = self::getTDBMService();
        $countryDao = new CountryDao($tdbmService);

        $countries = $countryDao->findAll();

        $countField = $type->getField('count');
        $resolveInfo = $this->getMockBuilder(ResolveInfo::class)->disableOriginalConstructor()->getMock();
        $resolveCallback = $countField->resolveFn;
        $this->assertEquals(3, $resolveCallback($countries, [], $resolveInfo));

        $itemsField = $type->getField('items');
        $resolveCallback = $itemsField->resolveFn;
        $result = $resolveCallback($countries, [], $resolveInfo);
        $this->assertCount(3, $result);
        $this->assertInstanceOf(Country::class, $result[0]);

        $result = $resolveCallback($countries, ['order'=>'label'], $resolveInfo);
        $this->assertEquals('Jamaica', $result[1]->getLabel());

        $result = $resolveCallback($countries, ['offset'=>1, 'limit'=>1], $resolveInfo);
        $this->assertCount(1, $result);

        $this->expectException(GraphQLException::class);
        $result = $resolveCallback($countries, ['offset'=>1], $resolveInfo);
    }
}
