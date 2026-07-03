<?php

namespace Demo;

use GenAI\Dto\Attribute\Dto;

/**
 * A value object with private properties + getters. #[Dto] makes it JSON-encode
 * correctly when returned from a #[RestController] (plain json_encode would yield
 * {} because the properties are private and JsonSerializable is PHP 5.4+).
 *
 * Runtime class (PHP 5.3-safe).
 */
#[Dto]
class UserDTO
{
    private $id;
    private $name;

    public function __construct($id, $name)
    {
        $this->id   = $id;
        $this->name = $name;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }
}
