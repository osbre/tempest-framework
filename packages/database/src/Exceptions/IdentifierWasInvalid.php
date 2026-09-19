<?php

namespace Tempest\Database\Exceptions;

use Exception;

final class IdentifierWasInvalid extends Exception implements DatabaseException
{
    public function __construct(string $identifier)
    {
        parent::__construct(sprintf('The identifier `%s` cannot be used in a query.', $identifier));
    }
}
