<?php

namespace Tempest\Database\Exceptions;

use Exception;

final class OrderByStatementWasInvalid extends Exception implements DatabaseException
{
    public function __construct(string $field)
    {
        parent::__construct(sprintf(
            '`%s` is not a valid field name. Pass the direction as the second argument, or use `orderByRaw()` for raw SQL.',
            $field,
        ));
    }
}
