<?php


namespace TheCodingMachine\Tdbm\GraphQL\Fixtures;

use TheCodingMachine\GraphQL\Controllers\Registry\RegistryInterface;
use TheCodingMachine\Tdbm\GraphQL\Tests\DAOs\UserDao;
use TheCodingMachine\Tdbm\GraphQL\Tests\GraphQL\UserType;
use Youshido\GraphQL\Config\Schema\SchemaConfig;
use Youshido\GraphQL\Field\InputField;
use Youshido\GraphQl\Relay\Connection\ArrayConnection;
use Youshido\GraphQL\Relay\Connection\Connection;
use Youshido\GraphQL\Relay\Fetcher\CallableFetcher;
use Youshido\GraphQL\Relay\Field\NodeField;
use Youshido\GraphQL\Relay\RelayMutation;
use Youshido\GraphQL\Schema\AbstractSchema;
use Youshido\GraphQL\Type\ListType\ListType;
use Youshido\GraphQL\Type\NonNullType;
use Youshido\GraphQL\Type\Scalar\StringType;

class TestSchema extends AbstractSchema
{

    /**
     * @var UserDao
     */
    private $userDao;
    /**
     * @var RegistryInterface
     */
    private $registry;

    public function __construct(RegistryInterface $registry, UserDao $userDao, array $config = [])
    {
        $this->userDao = $userDao;
        $this->registry = $registry;
        parent::__construct($config);
    }

    public function build(SchemaConfig $config)
    {
        /*$fetcher = new CallableFetcher(
            function ($type, $id) {
                switch ($type) {
                    case FactionType::TYPE_KEY:
                        return TestDataProvider::getFaction($id);

                    case
                    ShipType::TYPE_KEY:
                        return TestDataProvider::getShip($id);
                }

                return null;
            },
            function ($object) {
                return $object && array_key_exists('ships', $object) ? new FactionType() : new ShipType();
            }
        );*/

        $config->getQuery()
            //->addField(new NodeField($fetcher))
            ->addField('users', [
                'type'    => new ListType(new UserType($this->registry)),
                'resolve' => function () {
                    //return TestDataProvider::getFaction('rebels');
                    return $this->userDao->findAll();
                }
            ]);
    }
}
