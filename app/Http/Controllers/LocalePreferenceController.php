<?php

namespace App\Http\Controllers;

use App\Http\Resources\IdentityResource;
use App\Models\User;
use App\Support\IdentityInput;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class LocalePreferenceController extends Controller
{
    public function update(Request $request): IdentityResource
    {
        $input = IdentityInput::validate($request->all(), [
            'locale' => IdentityInput::localeRules(),
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['locale' => Arr::string($input, 'locale')])->save();

        return new IdentityResource($user);
    }
}
