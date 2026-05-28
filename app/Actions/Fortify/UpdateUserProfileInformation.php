<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use LogicException;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
        ])->validateWithBag('updateProfileInformation');

        if (array_key_exists('email', $input) && $input['email'] !== $user->email) {
            throw new LogicException('Email is an SL1 identity projection and cannot mutate identity authority.');
        }

        $user->fill([
            'name' => $input['name'],
        ])->save();
    }
}
