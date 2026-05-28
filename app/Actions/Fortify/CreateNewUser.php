<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use LogicException;

class CreateNewUser implements CreatesNewUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        throw new LogicException('Password registration is constitutionally disabled. Use SL1 Identity.');
    }
}
