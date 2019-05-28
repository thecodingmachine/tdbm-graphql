<?php

namespace TheCodingMachine\Tdbm\GraphQL;

use function copy;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use GraphQL\Error\Debug;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\SchemaConfig;
use Mouf\Picotainer\Picotainer;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionMethod;
use Symfony\Component\Cache\Simple\NullCache;
use TheCodingMachine\FluidSchema\TdbmFluidSchema;
use TheCodingMachine\GraphQLite\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationReader as DoctrineAnnotationReader;
use TheCodingMachine\GraphQLite\Annotations\FailWith;
use TheCodingMachine\GraphQLite\Annotations\Logged;
use TheCodingMachine\GraphQLite\Annotations\Right;
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
use TheCodingMachine\GraphQLite\SchemaFactory;
use TheCodingMachine\GraphQLite\Security\AuthenticationServiceInterface;
use TheCodingMachine\GraphQLite\Security\AuthorizationServiceInterface;
use TheCodingMachine\GraphQLite\Security\VoidAuthenticationService;
use TheCodingMachine\GraphQLite\Security\VoidAuthorizationService;
use TheCodingMachine\GraphQLite\TypeGenerator;
use TheCodingMachine\GraphQLite\TypeRegistry;
use TheCodingMachine\GraphQLite\Types\TypeResolver;
use TheCodingMachine\TDBM\Configuration;
use TheCodingMachine\Tdbm\GraphQL\Fixtures\Controllers\CountryController;
use TheCodingMachine\Tdbm\GraphQL\Fixtures\Controllers\UserController;
use TheCodingMachine\Tdbm\GraphQL\Registry\EmptyContainer;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\Country;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\Generated\AbstractCountry;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\Generated\AbstractFile;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\Generated\AbstractRole;
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
use function var_dump;
use function var_export;

class GraphQLTypeGeneratorTest extends TestCase
{
    /**
     * @var ContainerInterface
     */
    private $mainContainer;

    public function setUp()
    {
        $this->mainContainer = new Picotainer([
            Schema::class => function (ContainerInterface $container) {
                $factory = new SchemaFactory(new \Symfony\Component\Cache\Simple\ArrayCache(), $container->get(BasicAutoWiringContainer::class));
                $factory->addTypeNamespace('TheCodingMachine\\Tdbm\\GraphQL\\Tests\\GraphQL');
                $factory->addTypeNamespace('TheCodingMachine\\Tdbm\\GraphQL\\Tests\\Beans');
                $factory->addControllerNamespace('TheCodingMachine\\Tdbm\\GraphQL\\Fixtures\\Controllers');
                $factory->setAuthorizationService($container->get(AuthorizationServiceInterface::class));
                $factory->setAuthenticationService($container->get(AuthenticationServiceInterface::class));
                return $factory->createSchema();
            },
            BasicAutoWiringContainer::class => function (ContainerInterface $container) {
                return new BasicAutoWiringContainer($container);
            },
            AuthorizationServiceInterface::class => function (ContainerInterface $container) {
                return new VoidAuthorizationService();
            },
            AuthenticationServiceInterface::class => function (ContainerInterface $container) {
                return new VoidAuthenticationService();
            },
            UserController::class => function (ContainerInterface $container): UserController {
                $tdbmService = self::getTDBMService();
                return new UserController(new UserDao($tdbmService));
            },
            CountryController::class => function (ContainerInterface $container): CountryController {
                $tdbmService = self::getTDBMService();
                return new CountryController(new CountryDao($tdbmService));
            }
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
            ->uuid()->graphqlField()
            ->column('label')->string(255)->unique()->graphqlField();

        $db->table('person')
            ->id()->graphqlField()
            ->column('name')->string(255)->graphqlField();


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
            ->column('modified_at')->datetime()->null()->graphqlField()
            ->column('order')->integer()->null()->graphqlField();


        $db->table('contact')
            ->extends('person')
            ->column('email')->string(255)->graphqlField()
            ->column('manager_id')->references('contact')->null()->graphqlField();

        $db->table('users')
            ->extends('contact')
            ->column('login')->string(255)
            ->column('password')->string(255)->null()->graphqlField()->right('CAN_SEE_PASSWORD')->failWith(null)
            ->column('status')->string(10)->null()->default(null)->graphqlField()->logged()
            ->column('country_id')->references('country')->graphqlField();

        $db->table('rights')
            ->column('label')->string(255)->primaryKey()->comment('Non autoincrementable primary key')->graphqlField();

        $db->table('roles')
            ->id()
            ->column('name')->string(255)->graphqlField()
            ->column('created_at')->date()->null()->graphqlField()
            ->column('status')->boolean()->null()->default(1)->graphqlField();

        $db->table('roles_rights')
            ->column('role_id')->references('roles')
            ->column('right_label')->references('rights')->then()
            ->primaryKey(['role_id', 'right_label']);

        $db->junctionTable('users', 'roles')->graphqlField();

        $db->table('all_nullable')
            ->id()
            ->column('label')->string(255)->null()
            ->column('country_id')->references('country')->null();

        $db->table('animal')
            ->id()
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
            ->id()
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
            ->id()
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
            ->id()->graphqlField()
            ->column('file')->blob();

        $db->junctionTable('users', 'files')->graphqlField()->logged()->right('CAN_SEE_JOIN')->failWith([]);

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
            ->id()
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
            'uuid' => 'foo',
            'label' => 'France',
        ]);
        self::insert($connection, 'country', [
            'uuid' => 'bar',
            'label' => 'UK',
        ]);
        self::insert($connection, 'country', [
            'uuid' => 'baz',
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
            'country_id' => 'bar'
        ]);
        self::insert($connection, 'users', [
            'id' => 2,
            'login' => 'jean.dupont',
            'password' => null,
            'status' => 'on',
            'country_id' => 'foo'
        ]);
        self::insert($connection, 'users', [
            'id' => 3,
            'login' => 'robert.marley',
            'password' => null,
            'status' => 'off',
            'country_id' => 'baz'
        ]);
        self::insert($connection, 'users', [
            'id' => 4,
            'login' => 'bill.shakespeare',
            'password' => null,
            'status' => 'off',
            'country_id' => 'bar'
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
        $annotator = new GraphQLTypeAnnotator('TheCodingMachine\\Tdbm\\GraphQL\\Tests\\GraphQL');
        $configuration = new Configuration(
            'TheCodingMachine\\Tdbm\\GraphQL\\Tests\\Beans',
            'TheCodingMachine\\Tdbm\\GraphQL\\Tests\\DAOs',
            $connection,
            null,
            new ArrayCache(),
            null,
            null,
            [ $annotator ],
            null,
            [ $annotator ]
        );

        return new TDBMService($configuration);
    }

    public function testGenerate()
    {
        $this->recursiveDelete(__DIR__.'/../src/Tests/GraphQL/');

        $tdbmService = self::getTDBMService();
        $tdbmService->generateAllDaosAndBeans();

        /** @var AnnotationReader $reader */
        $reader = new AnnotationReader(new \Doctrine\Common\Annotations\AnnotationReader());
        /** @var Right $right */
        $right = $reader->getMiddlewareAnnotations(new ReflectionMethod(AbstractUser::class, 'getPassword'))->getAnnotationByType(Right::class);
        $this->assertNotNull($right);

        /** @var FailWith $failWith */
        $failWith = $reader->getMiddlewareAnnotations(new ReflectionMethod(AbstractUser::class, 'getPassword'))->getAnnotationByType(FailWith::class);
        $this->assertNotNull($failWith);
        $this->assertNull($failWith->getValue());

        /** @var Logged $logged */
        $logged = $reader->getMiddlewareAnnotations(new ReflectionMethod(AbstractUser::class, 'getStatus'))->getAnnotationByType(Logged::class);
        $this->assertNotNull($logged);

        $field = $reader->getRequestAnnotation(new ReflectionMethod(AbstractCountry::class, 'getUuid'), \TheCodingMachine\GraphQLite\Annotations\Field::class);
        $this->assertSame('ID', $field->getOutputType());

        $field = $reader->getRequestAnnotation(new ReflectionMethod(AbstractUser::class, 'getFiles'), \TheCodingMachine\GraphQLite\Annotations\Field::class);
        $this->assertNotNull($field);

        $field = $reader->getRequestAnnotation(new ReflectionMethod(AbstractFile::class, 'getUsers'), \TheCodingMachine\GraphQLite\Annotations\Field::class);
        $this->assertNotNull($field);

        $field = $reader->getRequestAnnotation(new ReflectionMethod(AbstractFile::class, 'getUsers'), \TheCodingMachine\GraphQLite\Annotations\Field::class);
        $this->assertNotNull($field);

        $field = $reader->getRequestAnnotation(new ReflectionMethod(AbstractUser::class, 'getCountry'), \TheCodingMachine\GraphQLite\Annotations\Field::class);
        $this->assertNull($field->getOutputType());
    }

    /**
     * @depends testGenerate
     */
    public function testQuery()
    {
        $schema = $this->mainContainer->get(Schema::class);

        $introspectionQuery = <<<EOF
{
  __schema {
    queryType {
      name
    }
  }
}
EOF;

        $response = GraphQL::executeQuery($schema, $introspectionQuery)->toArray(Debug::RETHROW_UNSAFE_EXCEPTIONS | Debug::RETHROW_INTERNAL_EXCEPTIONS);
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

        $response = GraphQL::executeQuery($schema, $introspectionQuery2)->toArray(Debug::RETHROW_UNSAFE_EXCEPTIONS | Debug::RETHROW_INTERNAL_EXCEPTIONS);
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
        $response = GraphQL::executeQuery($schema, $introspectionQuery3)->toArray(Debug::RETHROW_UNSAFE_EXCEPTIONS | Debug::RETHROW_INTERNAL_EXCEPTIONS);
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
        $schema = $this->mainContainer->get(Schema::class);
        $query = <<<EOF
{
  countries {
    items(offset: 1, limit: 1) {
        label
    }
    count
  }
}
EOF;
        $response = GraphQL::executeQuery($schema, $query)->toArray(Debug::RETHROW_UNSAFE_EXCEPTIONS | Debug::RETHROW_INTERNAL_EXCEPTIONS);

        $this->assertSame([
            'data' =>
                [
                    'countries' =>
                        [
                            'items' =>
                                [
                                        [
                                            'label' => 'Jamaica',
                                        ],
                                ],
                            'count' => 3,
                        ],
                ],
        ], $response);
    }
}
