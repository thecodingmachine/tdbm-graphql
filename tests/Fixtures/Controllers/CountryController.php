<?php


namespace TheCodingMachine\Tdbm\GraphQL\Fixtures\Controllers;

use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\Country;
use TheCodingMachine\Tdbm\GraphQL\Tests\Beans\User;
use TheCodingMachine\Tdbm\GraphQL\Tests\DAOs\CountryDao;
use TheCodingMachine\Tdbm\GraphQL\Tests\DAOs\UserDao;
use TheCodingMachine\TDBM\ResultIterator;

class CountryController
{
    /**
     * @var CountryDao
     */
    private $countryDao;

    public function __construct(CountryDao $countryDao)
    {
        $this->countryDao = $countryDao;
    }

    /**
     * @Query()
     * @return Country[]
     */
    public function countries(): ResultIterator
    {
        return $this->countryDao->findAll();
    }
}
