<?php

/**
 * Pony.fm - A community for pony fan music.
 * Copyright (C) 2015 Peter Deltchev
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Poniverse\Ponyfm\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Input;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Log;
use Poniverse\Lib\Client;
use Poniverse\Ponyfm\Models\Activity;
use Poniverse\Ponyfm\Models\User;
use Auth;
use DB;
use Request;
use Redirect;

class AuthController extends Controller
{
    protected $poniverse;

    public function __construct()
    {
        $this->poniverse = new Client(config('poniverse.client_id'), config('poniverse.secret'), new \GuzzleHttp\Client());
    }

    public function getLogin()
    {
        if (Auth::guest()) {
            return Redirect::to(
                $this->poniverse
                    ->getOAuthProvider(['redirectUri' => action('AuthController@getOAuth')])
                    ->getAuthorizationUrl());
        }

        return Redirect::to('/');
    }

    public function postLogout()
    {
        Auth::logout();
        return Redirect::to('/');
    }

    public function getOAuth()
    {
        $oauthProvider = $this->poniverse->getOAuthProvider();

        try {
            $accessToken = $oauthProvider->getAccessToken('authorization_code', [
                'code' => Request::query('code'),
                'redirect_uri' => action('AuthController@getOAuth')
            ]);
            $this->poniverse->setAccessToken($accessToken);
            $resourceOwner = $oauthProvider->getResourceOwner($accessToken);
        } catch (IdentityProviderException $e) {
            Log::error($e);

            return Redirect::to('/')->with(
                'message',
                'Unfortunately we are having problems attempting to log you in at the moment. Please try again at a later time.'
            );
        }

        /** @var \Poniverse\Lib\Entity\Poniverse\User $poniverseUser */
        $poniverseUser = $resourceOwner;

        $token = DB::table('oauth2_tokens')
            ->where('external_user_id', '=', $poniverseUser->id)
            ->where('service', '=', 'poniverse')
            ->first();

        $setData = [
            'access_token' => $accessToken,
            'expires' => Carbon::createFromTimestampUTC($accessToken->getExpires()),
            'type' => 'Bearer',
        ];

        if (!empty($accessToken->getRefreshToken())) {
            $setData['refresh_token'] = $accessToken->getRefreshToken();
        }

        if ($token) {
            //User already exists, update access token and refresh token if provided.
            DB::table('oauth2_tokens')->where('id', '=', $token->id)->update($setData);
            return $this->loginRedirect(User::find($token->user_id));
        }

        // Check by login name to see if they already have an account
        $user = User::findOrCreate($poniverseUser->username, $poniverseUser->display_name, $poniverseUser->email);

        if ($user->wasRecentlyCreated) {
            // We need to insert a new token row :O
            $setData['user_id'] = $user->id;
            $setData['external_user_id'] = $poniverseUser->id;
            $setData['service'] = 'poniverse';
            DB::table('oauth2_tokens')->insert($setData);

            // Subscribe the user to default email notifications
            foreach (Activity::DEFAULT_EMAIL_TYPES as $activityType) {
                $user->emailSubscriptions()->create(['activity_type' => $activityType]);
            }
        }


        return $this->loginRedirect($user);
    }

    /**
     * Processes requests to update a user's Poniverse information.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function postPoniverseAccountSync()
    {
        $poniverseId = Input::get('id');
        $updatedAttribute = Input::get('attribute');

        // Only email address updates are supported at this time.
        if ('email' !== $updatedAttribute) {
            return \Response::json(['message' => 'Unsupported Poniverse account attribute.'], 400);
        }

        $user = User::wherePoniverseId($poniverseId)->first();
        /** @var AccessToken $accessToken */
        $accessToken = $user->getAccessToken();

        if ($accessToken->hasExpired()) {
            $accessToken = $this->poniverse->getOAuthProvider()->getAccessToken('refresh_token', ['refresh_token' => $accessToken->getRefreshToken()]);
            $user->setAccessToken($accessToken);
        }

        /** @var \Poniverse\Lib\Entity\Poniverse\User $newUserData */
        $newUserData = $this->poniverse->getOAuthProvider()->getResourceOwner($accessToken);

        $user->{$updatedAttribute} = $newUserData->{$updatedAttribute};
        $user->save();

        return \Response::json(['message' => 'Successfully updated this user!'], 200);
    }

    protected function loginRedirect($user, $rememberMe = true)
    {
        Auth::login($user, $rememberMe);

        return Redirect::to('/');
    }
}
