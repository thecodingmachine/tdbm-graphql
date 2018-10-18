<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use GraphQL\Type\Definition\OutputType;
use TheCodingMachine\GraphQL\Controllers\Annotations\Right;
use TheCodingMachine\GraphQL\Controllers\Annotations\SourceFieldInterface;
use TheCodingMachine\Tdbm\GraphQL\Registry\Registry;

class Field implements SourceFieldInterface
{
    private $hide = true;
    private $right;
    /**
     * @var string
     */
    private $name;
    /**
     * @var bool
     */
    private $id;


    public function __construct(string $name, bool $isId = false)
    {
        $this->name = $name;
        $this->id = $isId;
    }
    
    /**
     * Hides this field.
     */
    public function hide()
    {
        $this->hide = true;
    }

    /**
     * Show this field if it was previously hidden.
     */
    public function show()
    {
        $this->hide = false;
    }

    /**
     * @return bool
     */
    public function isHidden(): bool
    {
        return $this->hide;
    }

    public function requiresRight(string $right)
    {
        $this->right = $right;
    }

    /**
     * Returns the GraphQL right to be applied to this source field.
     *
     * @return Right|null
     */
    public function getRight(): ?Right
    {
        if ($this->right !== null) {
            return new Right(['name' => $this->right]);
        } else {
            return null;
        }
    }

    /**
     * Returns the name of the GraphQL query/mutation/field.
     * If not specified, the name of the method should be used instead.
     *
     * @return null|string
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return bool
     */
    public function isLogged(): bool
    {
        return false;
    }

    /**
     * Returns the GraphQL return type of the request (as a string).
     * The string can represent the FQCN of the type or an entry in the container resolving to the GraphQL type.
     *
     * @return string|null
     */
    public function getReturnType(): ?string
    {
        return null;
    }

    /**
     * If the GraphQL type is "ID", isID will return true.
     *
     * @return bool
     */
    public function isId(): bool
    {
        return $this->id;
    }
}
