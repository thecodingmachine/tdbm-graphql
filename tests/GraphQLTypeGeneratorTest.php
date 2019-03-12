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
use ReflectionMethod;
use Symfony\Component\Cache\Simple\NullCache;
use TheCodingMachine\FluidSchema\TdbmFluidSchema;
use TheCodingMachine\GraphQLite\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationReader as DoctrineAnnotationReader;
use TheCodingMachine\GraphQLite\Containers\BasicAutoWiringContainer;
use TheCodingMachine\GraphQLite\FieldsBuilderFactory;
use TheCodingMachine\GraphQLite\Hydrators\FactoryHydrator;
use TheCodingMachine\GraphQLite\Hydrators\HydratorInterface;
use TheCodingMachine\GraphQLite\InputTypeGenerator;
use TheCodingMachine\GraphQLite\InputTypeUtils;
use TheCodingMachine\GraphQLite\Mappers\GlobTypeMapper;
use TheCodingMachine\GraphQLite\Mappers\RecursiveTypeMapper;
use TheCodingMachine\GraphQLite\Mappers\RecursiveTypeMapperInterface;
use TheCodingMachine\GraphQLite\Mappers\TypeMapperInterface;
use TheCodingMachine\GraphQLite\NamingStrategy;
use TheCodingMachine\GraphQLite\Reflection\CachedDocBlockFactory;
use TheCodingMachine\GraphQLite\Security\AuthenticationServiceInterface;
use TheCodingMachine\GraphQLite\Security\AuthorizationServiceInterface;
use TheCodingMachine\GraphQLite\Security\VoidAuthenticationService;
use TheCodingMachine\GraphQLite\Security\VoidAuthorizationService;
use TheCodingMachine\GraphQLite\TypeGenerator;
use TheCodingMachine\GraphQLite\TypeRegistry;
use TheCodingMachine\GraphQLite\Types\TypeResolver;
use TheCodingMachine\TDBM\Configuration;
use TheCodingMachine\Tdbm\GraphQL\Registry\EmptyContainer;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\Country;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\Generated\AbstractUser;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\User;
use TheCodingMachine\Tdbm\GraphQL\Tests\DAOs\CountryDao;
use TheCodingMachine\Tdbm\GraphQL\Tests\DAOs\UserDao;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\CountryType;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\Generated\AbstractCountryType;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\Generated\AbstractUserType;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\UserType;
use TheCodingMachine\TDBM\TDBMService;
use PHPUnit\Framework\TestCase;
use GraphQL\Type\Schema;

class GraphQLTypeGeneratorTest extends TestCase
{
    /**
     * @var ContainerInterface
     */
    private $mainContainer;

    public function setUp()
    {
        $this->mainContainer = new Picotainer([
            FieldsBuilderFactory::class => function (ContainerInterface $container) {
                return new FieldsBuilderFactory(
                    $container->get(AnnotationReader::class),
                    $container->get(HydratorInterface::class),
                    $container->get(AuthenticationServiceInterface::class),
                    $container->get(AuthorizationServiceInterface::class),
                    $container->get(TypeResolver::class),
                    $container->get(CachedDocBlockFactory::class),
                    $container->get(NamingStrategyInterface::class)
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
            RecursiveTypeMapperInterface::class => function (ContainerInterface $container) {
                return new RecursiveTypeMapper(
                    $container->get(TypeMapperInterface::class),
                    $container->get(NamingStrategyInterface::class),
                    new \Symfony\Component\Cache\Simple\ArrayCache(),
                    $container->get(TypeRegistry::class)
                );
            },
            TypeMapperInterface::class => function (ContainerInterface $container) {
                return new GlobTypeMapper(
                    'TheCodingMachine\\Tdbm\\GraphQL\\Tests\\GraphQL',
                    $container->get(TypeGenerator::class),
                    $container->get(InputTypeGenerator::class),
                    $container->get(InputTypeUtils::class),
                    $container->get(BasicAutoWiringContainer::class),
                    $container->get(AnnotationReader::class),
                    $container->get(NamingStrategyInterface::class),
                    new \Symfony\Component\Cache\Simple\ArrayCache()
                );
            },
            TypeGenerator::class => function (ContainerInterface $container) {
                return new TypeGenerator(
                    $container->get(AnnotationReader::class),
                    $container->get(FieldsBuilderFactory::class),
                    $container->get(NamingStrategyInterface::class),
                    $container->get(TypeRegistry::class),
                    $container->get(BasicAutoWiringContainer::class)
                );
            },
            TypeRegistry::class => function () {
                return new TypeRegistry();
            },
            AnnotationReader::class => function (ContainerInterface $container) {
                return new AnnotationReader(new DoctrineAnnotationReader());
            },
            HydratorInterface::class => function (ContainerInterface $container) {
                return new FactoryHydrator();
            },
            InputTypeGenerator::class => function (ContainerInterface $container) {
                return new InputTypeGenerator(
                    $container->get(InputTypeUtils::class),
                    $container->get(FieldsBuilderFactory::class),
                    $container->get(HydratorInterface::class)
                );
            },
            InputTypeUtils::class => function (ContainerInterface $container) {
                return new InputTypeUtils(
                    $container->get(AnnotationReader::class),
                    $container->get(NamingStrategyInterface::class)
                );
            },
            TypeResolver::class => function (ContainerInterface $container) {
                return new TypeResolver();
            },
            CachedDocBlockFactory::class => function () {
                return new CachedDocBlockFactory(new \Symfony\Component\Cache\Simple\ArrayCache());
            },
            NamingStrategyInterface::class => function () {
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

        self::initSchema($conn);
    }

    private static function initSchema(Connection $connection): void
    {
        $fromSchema = $connection->getSchemaManager()->createSchema();
        $toSchema = clone $fromSchema;

        $db = new TdbmFluidSchema($toSchema, new \TheCodingMachine\FluidSchema\DefaultNamingStrategy($connection->getDatabasePlatform()));

        $db->table('country')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')->graphql()
            ->column('label')->string(255)->unique()->graphql();

        $db->table('person')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')->graphql()
            ->column('name')->string(255)->graphql();

        if ($connection->getDatabasePlatform() instanceof OraclePlatform) {
            $toSchema->getTable($connection->quoteIdentifier('person'))
                ->addColumn(
                    $connection->quoteIdentifier('created_at'),
                    'datetime',
                    ['columnDefinition' => 'TIMESTAMP(0) DEFAULT SYSDATE NOT NULL']
                );
        } else {
            $toSchema->getTable('person')
                ->addColumn(
                    $connection->quoteIdentifier('created_at'),
                    'datetime',
                    ['columnDefinition' => 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP']
                );
        }

        $db->table('person')
            ->column('modified_at')->datetime()->null()->graphql()
            ->column('order')->integer()->null()->graphql();


        $db->table('contact')
            ->extends('person')
            ->column('email')->string(255)->graphql()
            ->column('manager_id')->references('contact')->null()->graphql();

        $db->table('users')
            ->extends('contact')
            ->column('login')->string(255)
            ->column('password')->string(255)->null()->graphql()->right('CAN_SEE_PASSWORD')->failWith(null)
            ->column('status')->string(10)->null()->default(null)->graphql()->logged()
            ->column('country_id')->references('country')->graphql();

        $db->table('rights')
            ->column('label')->string(255)->primaryKey()->comment('Non autoincrementable primary key')->graphql();

        $db->table('roles')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('name')->string(255)->graphql()
            ->column('created_at')->date()->null()->graphql()
            ->column('status')->boolean()->null()->default(1)->graphql();

        $db->table('roles_rights')
            ->column('role_id')->references('roles')
            ->column('right_label')->references('rights')->then()
            ->primaryKey(['role_id', 'right_label']);

        $db->junctionTable('users', 'roles')->graphql()->logged()->right('CAN_SEE_JOIN')->failWith([]);

        $db->table('all_nullable')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('label')->string(255)->null()
            ->column('country_id')->references('country')->null();

        $db->table('animal')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('name')->string(45)->index()
            ->column('UPPERCASE_COLUMN')->string(45)->null()
            ->column('order')->integer()->null();

        $db->table('dog')
            ->extends('animal')
            ->column('race')->string(45)->null();

        $db->table('cat')
            ->extends('animal')
            ->column('cuteness_level')->integer()->null();

        $db->table('panda')
            ->extends('animal')
            ->column('weight')->float()->null();

        $db->table('boats')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('name')->string(255)
            ->column('anchorage_country')->references('country')->notNull()->then()
            ->column('current_country')->references('country')->null()->then()
            ->column('length')->decimal(10, 2)->null()->then()
            ->unique(['anchorage_country', 'name']);

        $db->table('sailed_countries')
            ->column('boat_id')->references('boats')
            ->column('country_id')->references('country')
            ->then()->primaryKey(['boat_id', 'country_id']);

        $db->table('category')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('label')->string(255)
            ->column('parent_id')->references('category')->null();

        $db->table('articles')
            ->column('id')->string(36)->primaryKey()->comment('@UUID')
            ->column('content')->string(255)
            ->column('author_id')->references('users')->null();

        $db->table('articles2')
            ->column('id')->string(36)->primaryKey()->comment('@UUID v4')
            ->column('content')->string(255);

        $db->table('files')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('file')->blob();

        $toSchema->getTable('users')
            ->addUniqueIndex([$connection->quoteIdentifier('login')], 'users_login_idx')
            ->addIndex([$connection->quoteIdentifier('status'), $connection->quoteIdentifier('country_id')], 'users_status_country_idx');

        // We create the same index twice
        // except for Oracle that won't let us create twice the same index.
        if (!$connection->getDatabasePlatform() instanceof OraclePlatform) {
            $toSchema->getTable('users')
                ->addUniqueIndex([$connection->quoteIdentifier('login')], 'users_login_idx_2');
        }

        // A table with a foreign key that references a non primary key.
        $db->table('ref_no_prim_key')
            ->column('id')->integer()->primaryKey()->autoIncrement()->comment('@Autoincrement')
            ->column('from')->string(50)
            ->column('to')->string(50)->unique();

        $toSchema->getTable($connection->quoteIdentifier('ref_no_prim_key'))->addForeignKeyConstraint($connection->quoteIdentifier('ref_no_prim_key'), [$connection->quoteIdentifier('from')], [$connection->quoteIdentifier('to')]);

        // A table with multiple primary keys.
        $db->table('states')
            ->column('country_id')->references('country')
            ->column('code')->string(3)
            ->column('name')->string(50)->then()
            ->primaryKey(['country_id', 'code']);

        $sqlStmts = $toSchema->getMigrateFromSql($fromSchema, $connection->getDatabasePlatform());

        foreach ($sqlStmts as $sqlStmt) {
            $connection->exec($sqlStmt);
        }

        self::insert($connection, 'country', [
            'label' => 'France',
        ]);
        self::insert($connection, 'country', [
            'label' => 'UK',
        ]);
        self::insert($connection, 'country', [
            'label' => 'Jamaica',
        ]);

        self::insert($connection, 'person', [
            'name' => 'John Smith',
            'created_at' => '2015-10-24 11:57:13',
        ]);
        self::insert($connection, 'person', [
            'name' => 'Jean Dupont',
            'created_at' => '2015-10-24 11:57:13',
        ]);
        self::insert($connection, 'person', [
            'name' => 'Robert Marley',
            'created_at' => '2015-10-24 11:57:13',
        ]);
        self::insert($connection, 'person', [
            'name' => 'Bill Shakespeare',
            'created_at' => '2015-10-24 11:57:13',
        ]);

        self::insert($connection, 'contact', [
            'id' => 1,
            'email' => 'john@smith.com',
            'manager_id' => null,
        ]);
        self::insert($connection, 'contact', [
            'id' => 2,
            'email' => 'jean@dupont.com',
            'manager_id' => null,
        ]);
        self::insert($connection, 'contact', [
            'id' => 3,
            'email' => 'robert@marley.com',
            'manager_id' => null,
        ]);
        self::insert($connection, 'contact', [
            'id' => 4,
            'email' => 'bill@shakespeare.com',
            'manager_id' => 1,
        ]);

        self::insert($connection, 'rights', [
            'label' => 'CAN_SING',
        ]);
        self::insert($connection, 'rights', [
            'label' => 'CAN_WRITE',
        ]);

        self::insert($connection, 'roles', [
            'name' => 'Admins',
            'created_at' => '2015-10-24'
        ]);
        self::insert($connection, 'roles', [
            'name' => 'Writers',
            'created_at' => '2015-10-24'
        ]);
        self::insert($connection, 'roles', [
            'name' => 'Singers',
            'created_at' => '2015-10-24'
        ]);

        self::insert($connection, 'roles_rights', [
            'role_id' => 1,
            'right_label' => 'CAN_SING'
        ]);
        self::insert($connection, 'roles_rights', [
            'role_id' => 3,
            'right_label' => 'CAN_SING'
        ]);
        self::insert($connection, 'roles_rights', [
            'role_id' => 1,
            'right_label' => 'CAN_WRITE'
        ]);
        self::insert($connection, 'roles_rights', [
            'role_id' => 2,
            'right_label' => 'CAN_WRITE'
        ]);

        self::insert($connection, 'users', [
            'id' => 1,
            'login' => 'john.smith',
            'password' => null,
            'status' => 'on',
            'country_id' => 2
        ]);
        self::insert($connection, 'users', [
            'id' => 2,
            'login' => 'jean.dupont',
            'password' => null,
            'status' => 'on',
            'country_id' => 1
        ]);
        self::insert($connection, 'users', [
            'id' => 3,
            'login' => 'robert.marley',
            'password' => null,
            'status' => 'off',
            'country_id' => 3
        ]);
        self::insert($connection, 'users', [
            'id' => 4,
            'login' => 'bill.shakespeare',
            'password' => null,
            'status' => 'off',
            'country_id' => 2
        ]);

        self::insert($connection, 'users_roles', [
            'user_id' => 1,
            'role_id' => 1,
        ]);
        self::insert($connection, 'users_roles', [
            'user_id' => 2,
            'role_id' => 1,
        ]);
        self::insert($connection, 'users_roles', [
            'user_id' => 3,
            'role_id' => 3,
        ]);
        self::insert($connection, 'users_roles', [
            'user_id' => 4,
            'role_id' => 2,
        ]);
        self::insert($connection, 'users_roles', [
            'user_id' => 3,
            'role_id' => 2,
        ]);

        self::insert($connection, 'ref_no_prim_key', [
            'from' => 'foo',
            'to' => 'foo',
        ]);
    }

    protected static function insert(Connection $connection, string $tableName, array $data): void
    {
        $quotedData = [];
        foreach ($data as $id => $value) {
            $quotedData[$connection->quoteIdentifier($id)] = $value;
        }
        $connection->insert($connection->quoteIdentifier($tableName), $quotedData);
    }

    protected static function getTDBMService() : TDBMService
    {
        $config = new \Doctrine\DBAL\Configuration();
        $connection = DriverManager::getConnection(self::getConnectionParams(), $config);
        $configuration = new Configuration(
            'TheCodingMachine\\Tdbm\\GraphQL\\Tests\\Beans',
            'TheCodingMachine\\Tdbm\\GraphQL\\Tests\\DAOs',
            $connection,
            null,
            new ArrayCache(),
            null,
            null,
            [],
            null,
            [ new GraphQLTypeAnnotator('TheCodingMachine\\Tdbm\\GraphQL\\Tests\\GraphQL') ]
        );

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

        /** @var AnnotationReader $reader */
        $reader = $this->mainContainer->get(AnnotationReader::class);
        $right = $reader->getRightAnnotation(new ReflectionMethod(AbstractUser::class, 'getPassword'));
        $this->assertNotNull($right);

        $failWith = $reader->getFailWithAnnotation(new ReflectionMethod(AbstractUser::class, 'getPassword'));
        $this->assertNotNull($failWith);
        $this->assertNull($failWith->getValue());

        $logged = $reader->getLoggedAnnotation(new ReflectionMethod(AbstractUser::class, 'getStatus'));
        $this->assertNotNull($logged);
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
                    'type'    => Type::listOf($this->mainContainer->get(RecursiveTypeMapperInterface::class)->mapClassToType(User::class, null)),
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
        $type = new ResultIteratorType($this->mainContainer->get(RecursiveTypeMapperInterface::class)->mapClassToType(Country::class, null));

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
