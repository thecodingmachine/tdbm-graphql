<?php

namespace TheCodingMachine\Tdbm\GraphQL\Annotations;

use function file_get_contents;
use PHPUnit\Framework\TestCase;
use function str_replace;

/**
 * Test case for AnnotationAdder
 */
class AnnotationAdderTest extends TestCase
{
    public function testAddAnnotation(): void
    {
        $adder = new AnnotationAdder();
        $initialCode = file_get_contents(__FILE__);
        $code = $adder->addAnnotation($initialCode);
        $this->assertContains(" @Type\n */\nclass", $code);
        $this->assertContains('use TheCodingMachine\\GraphQLite\\Annotations\\Type;', $code);

        $code = $adder->addAnnotation(str_replace('class ', 'final class ', $initialCode));
        $this->assertContains(" @Type\n */\nfinal class", $code);
        $this->assertContains('use TheCodingMachine\\GraphQLite\\Annotations\\Type;', $code);

        $this->assertFalse($adder->hasAnnotation($initialCode));
        $this->assertTrue($adder->hasAnnotation($code));
    }
}
