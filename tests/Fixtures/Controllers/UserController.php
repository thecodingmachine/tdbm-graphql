<?php


namespace TheCodingMachine\Tdbm\GraphQL\Fixtures\Controllers;

use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\User;
use TheCodingMachine\Tdbm\GraphQL\Tests\DAOs\UserDao;

class UserController
{
    /**
     * @var UserDao
     */
    private $userDao;

    public function __construct(UserDao $userDao)
    {
        $this->userDao = $userDao;
    }

    /**
     * @Query()
     * @return User[]
     */
    public function users(): array
    {
        return $this->userDao->findAll()->toArray();
    }
}
