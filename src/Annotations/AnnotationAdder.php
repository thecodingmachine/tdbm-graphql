<?php


namespace TheCodingMachine\Tdbm\GraphQL\Annotations;

use function preg_replace;
use function strpos;

/**
 * Adds a "Type" annotation to an existing file
 */
class AnnotationAdder
{
    public function hasAnnotation(string $code): bool
    {
        return strpos($code, 'use TheCodingMachine\GraphQLite\Annotations\Type') !== false &&
            strpos($code, '@Type') !== false;
    }

    public function addAnnotation(string $code): string
    {
        if (strpos($code, 'use TheCodingMachine\GraphQLite\Annotations\Type') === false) {
            $code = preg_replace("#namespace (.*);#", "namespace \${1};\n\nuse TheCodingMachine\\GraphQLite\\Annotations\\Type;", $code);
        }
        $code = preg_replace("# */\n(.*)class#", " @Type\n */\n\${1}class", $code);

        return $code;
    }
}
