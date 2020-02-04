<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

use App\Http\Controllers\Controller;

use App\Models\Person;
use App\Models\Role;
use App\Models\ActionLog;
use App\Models\ErrorLog;
use App\Http\RestApi;
use App\Mail\ResetPassword;
use App\Helpers\SqlHelper;

use GuzzleHttp;
use GuzzleHttp\Exception\RequestException;

class AuthController extends Controller
{
    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */

    public function login()
    {
        $code = request()->input('sso_code');

        if (!empty($code)) {
            return $this->handleSSOLogin($code);
        }

        $credentials = request()->validate([
            'identification' => 'required|string',
            'password'       => 'required|string',
        ]);

        $actionData = $this->buildLogInfo();

        $person = Person::where('email', $credentials['identification'])->first();
        if (!$person) {
            $actionData['email'] = $credentials['identification'];
            ActionLog::record(null, 'auth-failed', 'Email not found', $actionData);
            return response()->json([ 'status' => 'invalid-credentials'], 401);
        }

        if (!$person->isValidPassword($credentials['password'])) {
            ActionLog::record($person, 'auth-failed', 'Password incorrect', $actionData);
            return response()->json([ 'status' => 'invalid-credentials'], 401);
        }

        return $this->attemptLogin($person, $actionData);

    }

    private function buildLogInfo() {
        $actionData = [
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        $screenSize = request()->input('screen_size');

        // Analytics to help figure out how our users interact with the site.
        if (!empty($screenSize)) {
            $actionData['screen_size'] = $screenSize;
        }

        return $actionData;
    }

    /**
     * Attempt to login (aka responsed with a token) the user
     *
     * Handles the common checks for both username/password and SSO logins.
     */

    private function attemptLogin(Person $person, $actionData) {
        $status = $person->status;

        if ($status == Person::SUSPENDED) {
            ActionLog::record($person, 'auth-failed', 'Account suspended', $actionData);
            return response()->json([ 'status' => 'account-suspended'], 401);
        }

        if ($person->user_authorized == false
        || in_array($status, Person::LOCKED_STATUSES)) {
            ActionLog::record($person, 'auth-failed', 'Account disabled', $actionData);
            return response()->json([ 'status' => 'account-disabled'], 401);
        }

        if (!$person->hasRole(Role::LOGIN)) {
            ActionLog::record($person, 'auth-failed', 'Login disabled', $actionData);
            return response()->json([ 'status' => 'login-disabled' ], 401);
        }

        $lastLoggedIn = $person->logged_in_at;
        $person->logged_in_at = SqlHelper::now();
        $person->saveWithoutValidation();

        ActionLog::record($person, 'auth-login', 'User login', $actionData);
        return $this->respondWithToken(auth()->login($person), $person, $lastLoggedIn);
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        ActionLog::record($this->user, 'auth-logout', 'User logout');
        auth()->logout();

        return response()->json(['status' => 'success']);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        // TODO - test this
        return $this->respondWithToken(auth()->refresh());
    }

    /**
     * Reset an account password by emailing a new temporary password.
     */

    public function resetPassword()
    {
        $data = request()->validate([
            'identification' => 'required|email',
        ]);

        $action = [
            'ip'            => request()->ip(),
            'user_agent'    => request()->userAgent(),
            'email'         => $data['identification']
        ];

        $person = Person::findByEmail($data['identification']);

        if (!$person) {
            ActionLog::record(null, 'auth-password-reset-fail', 'Password reset failed', $action);
            return response()->json([ 'status' => 'not-found' ], 400);
        }

        if (!$person->user_authorized) {
            ActionLog::record(null, 'auth-password-reset-fail', 'Account disabled', $action);
            return response()->json([ 'status' => 'account-disabled' ], 403);
        }

        $resetPassword = $person->createResetPassword();

        ActionLog::record($person, 'auth-password-reset-success', 'Password reset request', $action);

        if (!mail_to($person->email, new ResetPassword($resetPassword, setting('GeneralSupportEmail')))) {
            return response()->json([ 'status' => 'mail-fail' ]);
        }

        return response()->json([ 'status' => 'success' ]);
    }

    /**
     *
     * Handle a Okta (for now) SSO login.
     *
     * Query the SSO server to obtain the "claims" values and figure out who
     * the person is trying to login.
     */

    private function handleSSOLogin($code)
    {
        $clientId = config('okta.client_id');
        $issuer = config('okta.issuer');
        $authHeaderSecret = base64_encode($clientId . ':' . config('okta.client_secret'));

        $url =  $issuer . '/v1/token';
        $client = new GuzzleHttp\Client();

        $actionData = $this->buildLogInfo();

        try {
            // Query the server - note the code is only valid for a few seconds.
            $res = $client->request('POST', $url, [
                'read_timeout' => 10,
                'connect_timeout' => 10,
                'query' => [
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => config('okta.redirect_uri'),
                    'code' => $code,
                ],
                'headers' => [
                    'Authorization' => "Basic $authHeaderSecret",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
            ]);
        } catch (RequestException $e) {
            ErrorLog::recordException($e, 'auth-sso-connect-failure');
            return response()->json([ 'status' => 'sso-server-failure' ], 401);
        }

        $body = $res->getBody()->getContents();

        if ($res->getStatusCode() != 200) {
            ErrorLog::record('sso-server-failure', [ 'status' => $res->getStatusCode(), 'body' => $res->getBody() ]);
            return response()->json([ 'status' => 'sso-server-failure' ], 401);
        }

        try {
            // Try to decode the token
            $json = json_decode($body);
            $jwtVerifier = (new \Okta\JwtVerifier\JwtVerifierBuilder())
                ->setIssuer($issuer)
                ->setAudience('api://default')
                ->setClientId($clientId)
                ->build();

            $jwt = $jwtVerifier->verify($json->access_token);
            if (!$jwt) {
                ErrorLog::record('sso-malformed-token', [ 'body' => $body, 'jwt' => $jwt ]);
                return response()->json([ 'status' => 'sso-token-failure' ], 401);
            }

            $claims = $jwt->claims;
        } catch (\Exception $e) {
            ErrorLog::recordException($e, 'sso-decode-failure', [ 'body' => $body ]);
            return response()->json([ 'status' => 'sso-token-failure' ], 401);
        }

        /*
         * TODO: if the plans go forward to support Okta SSO, the claims
         * values will need to include a BPGUID to correctly identify the
         * account. Email is not enough because the Clubhouse & SSO service
         * might be out of sync.
         */

        $email = $jwt->claims['sub'];
        $person = Person::where('email', $email)->first();
        if (!$person) {
            $actionData['email'] = $email;
            ActionLog::record(null, 'auth-sso-failed', 'Email not found', $actionData);
            return response()->json([ 'status' => 'invalid-credentials'], 401);
        }

        // Everything looks good so far.. perform some validation checks and
        // response with a token
        return $this->attemptLogin($person, $actionData);
    }

    /**
     * Get the JWT token array structure.
     *
     * @param string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */

    protected function respondWithToken($token, $person, $lastLoggedIn)
    {
        // TODO does a 'refresh_token' need to be provided?
        return response()->json([
            'token'      => $token,
            'person_id'  => $person->id,
            'last_logged_in' => (string) $lastLoggedIn,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60
        ]);
    }
}
