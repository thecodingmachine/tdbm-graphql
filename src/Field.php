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
    /**
     * @var mixed
     */
    private $failWithValue;
    /**
     * @var bool
     */
    private $hasFailWith = false;


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

    public function requiresRight(string $right): self
    {
        $this->right = $right;
        return $this;
    }

    public function failWith($defaultValue): self
    {
        $this->failWithValue = $defaultValue;
        $this->hasFailWith = true;
        return $this;
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
     * The string is the GraphQL output type name.
     *
     * @return string|null
     */
    public function getOutputType(): ?string
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

    /**
     * Returns the default value to use if the right is not enforced.
     *
     * @return mixed
     */
    public function getFailWith()
    {
        return $this->failWithValue;
    }

    /**
     * True if a default value is available if a right is not enforced.
     *
     * @return bool
     */
    public function canFailWith()
    {
        return $this->hasFailWith;
    }
}
