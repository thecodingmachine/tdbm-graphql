<?php

namespace TheCodingMachine\Tdbm\GraphQL;

use PHPUnit\Framework\TestCase;
use TheCodingMachine\GraphQL\Controllers\Registry\Registry;
use TheCodingMachine\GraphQL\Controllers\Security\AuthorizationServiceInterface;
use TheCodingMachine\GraphQL\Controllers\Security\VoidAuthenticationService;
use TheCodingMachine\Tdbm\GraphQL\Registry\EmptyContainer;
use Youshido\GraphQL\Type\Scalar\StringType;

class FieldTest extends TestCase
{
    public function testIsHidden()
    {
        $field = new Field('test');

        $this->assertTrue($field->isHidden());
        $field->show();
        $this->assertFalse($field->isHidden());
        $field->hide();
        $this->assertTrue($field->isHidden());

        $field->requiresRight('nope');
    }

    public function testFailWith()
    {
        $field = new Field('test');
        $this->assertFalse($field->canFailWith());
        $field->failWith('foo');
        $this->assertTrue($field->canFailWith());
        $this->assertSame('foo', $field->getFailWith());
    }
}
