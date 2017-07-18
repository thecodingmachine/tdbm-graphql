<?php

namespace TheCodingMachine\Tdbm\GraphQL;

use PHPUnit\Framework\TestCase;
use TheCodingMachine\Tdbm\GraphQL\Registry\AuthorizationServiceInterface;
use TheCodingMachine\Tdbm\GraphQL\Registry\EmptyContainer;
use TheCodingMachine\Tdbm\GraphQL\Registry\Registry;
use Youshido\GraphQL\Type\Scalar\StringType;

class FieldTest extends TestCase
{
    public function testIsHidden()
    {
        $authorizationService = new class implements AuthorizationServiceInterface {
            public function isAllowed(string $right): bool
            {
                return ($right === 'ok');
            }
        };

        $registry = new Registry(new EmptyContainer(), $authorizationService);

        $field = new Field('test', new StringType(), $registry);

        $this->assertTrue($field->isHidden());
        $field->show();
        $this->assertFalse($field->isHidden());
        $field->hide();
        $this->assertTrue($field->isHidden());

        $field->requiresRight('nope');
    }
}
