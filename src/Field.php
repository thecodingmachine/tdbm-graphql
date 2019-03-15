<?php


namespace TheCodingMachine\Tdbm\GraphQL;

use GraphQL\Type\Definition\OutputType;
use TheCodingMachine\GraphQLite\Annotations\Right;
use TheCodingMachine\GraphQLite\Annotations\SourceFieldInterface;
use TheCodingMachine\Tdbm\GraphQL\Registry\Registry;

/**
 * @deprecated Use db column "Field" annotation instead
 */
class Field implements SourceFieldInterface
{
    private $hide = true;
    /**
     * @var bool
     */
    private $logged = false;
    /**
     * @var string
     */
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
     * @deprecated Use db column "Field" annotation instead
     */
    public function hide(): void
    {
        $this->hide = true;
    }

    /**
     * Show this field if it was previously hidden.
     * @deprecated Use db column "Field" annotation instead
     */
    public function show(): void
    {
        $this->hide = false;
    }

    /**
     * @deprecated
     * @return bool
     */
    public function isHidden(): bool
    {
        return $this->hide;
    }

    /**
     * The user must be logged to access this field.
     * @deprecated Use db column "Logged" annotation instead
     */
    public function logged(): self
    {
        $this->logged = true;
        return $this;
    }

    /**
     * The user must have right $right to access this field.
     * @deprecated Use db column "Right" annotation instead
     */
    public function requiresRight(string $right): self
    {
        $this->right = $right;
        return $this;
    }

    /**
     * @deprecated Use db column "FailWith" annotation instead
     */
    public function failWith($defaultValue): self
    {
        $this->failWithValue = $defaultValue;
        $this->hasFailWith = true;
        return $this;
    }

    /**
     * Returns the GraphQL right to be applied to this source field.
     *
     * @deprecated
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
     * @deprecated
     * @return null|string
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @deprecated
     * @return bool
     */
    public function isLogged(): bool
    {
        return $this->logged;
    }

    /**
     * Returns the GraphQL return type of the request (as a string).
     * The string is the GraphQL output type name.
     *
     * @deprecated
     * @return string|null
     */
    public function getOutputType(): ?string
    {
        return null;
    }

    /**
     * If the GraphQL type is "ID", isID will return true.
     *
     * @deprecated
     * @return bool
     */
    public function isId(): bool
    {
        return $this->id;
    }

    /**
     * Returns the default value to use if the right is not enforced.
     *
     * @deprecated
     * @return mixed
     */
    public function getFailWith()
    {
        return $this->failWithValue;
    }

    /**
     * True if a default value is available if a right is not enforced.
     *
     * @deprecated
     * @return bool
     */
    public function canFailWith()
    {
        return $this->hasFailWith;
    }
}
