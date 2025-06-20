<?php

/**
 * NexoPOS Controller
 *
 * @since  1.0
 **/

namespace Ns\Http\Controllers;

use Ns\Classes\Hook;
use Ns\Classes\JsonResponse;
use Ns\Events\AfterSuccessfulLoginEvent;
use Ns\Events\BeforeSignInEvent;
use Ns\Events\BeforeSignUpEvent;
use Ns\Events\PasswordAfterRecoveredEvent;
use Ns\Events\UserAfterActivationSuccessfulEvent;
use Ns\Exceptions\NotAllowedException;
use Ns\Exceptions\NotFoundException;
use Ns\Http\Requests\PostNewPasswordRequest;
use Ns\Http\Requests\PostPasswordLostRequest;
use Ns\Http\Requests\SignInRequest;
use Ns\Http\Requests\SignUpRequest;
use Ns\Mail\ResetPasswordMail;
use Ns\Models\User;
use Ns\Services\UsersService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct( private UsersService $userService ) {}

    public function signIn()
    {
        return view( Hook::filter( 'ns-views:pages.sign-in', 'ns::pages.auth.sign-in' ), [
            'title' => __( 'Sign In — NexoPOS' ),
        ] );
    }

    public function signUp()
    {
        return view( Hook::filter( 'ns-views:pages.sign-up', 'ns::pages.auth.sign-up' ), [
            'title' => __( 'Sign Up — NexoPOS' ),
        ] );
    }

    public function activateAccount( User $user, $token )
    {
        /**
         * trying to activate an already activated
         * account ? Not possible.
         */
        if ( $user->active ) {
            return redirect( nsRoute( Hook::filter( 'ns.sign-in.route', 'ns.login' ) ) )->with( 'errorMessage', __( 'No activation is needed for this account.' ) );
        }

        /**
         * The activation is not valid.
         * let's throw an exception.
         */
        if ( $user->activation_token !== $token || $user->activation_token === null ) {
            return redirect( nsRoute( Hook::filter( 'ns.sign-in.route', 'ns.login' ) ) )->with( 'errorMessage', __( 'Invalid activation token.' ) );
        }

        /**
         * The activationt token has expired. Let's redirect
         * the user to the login page with a message.
         */
        if ( ! ns()->date->lessThan( Carbon::parse( $user->activation_expiration ) ) ) {
            return redirect( nsRoute( Hook::filter( 'ns.sign-in.route', 'ns.login' ) ) )->with( 'errorMessage', __( 'The expiration token has expired.' ) );
        }

        $user->activation_expiration = null;
        $user->activation_token = null;
        $user->active = true;
        $user->save();

        /**
         * We'll dispatch an event to warn every
         * component that needs to be aware of that.
         */
        UserAfterActivationSuccessfulEvent::dispatch( $user );

        return redirect( nsRoute( 'ns.login' ) )->with( 'message', __( 'Your account is now activated.' ) );
    }

    public function passwordLost()
    {
        return view( 'ns::pages.auth.password-lost', [
            'title' => __( 'Password Lost' ),
        ] );
    }

    public function newPassword( $userId, $token )
    {
        $user = User::find( $userId );

        if ( ! $user->active ) {
            throw new NotAllowedException( __( 'Unable to change a password for a non active user.' ) );
        }

        if ( $user->activation_token !== $token ) {
            throw new NotAllowedException( __( 'Unable to proceed as the token provided is invalid.' ) );
        }

        if ( Carbon::parse( $user->activation_expiration )->lessThan( now() ) ) {
            throw new NotAllowedException( __( 'The token has expired. Please request a new activation token.' ) );
        }

        return view( Hook::filter( 'ns-views.auth.new-password', 'ns::pages.auth.new-password' ), [
            'title' => __( 'Set New Password' ),
            'user' => $userId,
            'token' => $token,
        ] );
    }

    public function signOut( Request $request )
    {
        Auth::logout();

        $request->session()->flush();
        $request->cookie( 'nexopos_session', null, 0 );

        return redirect( nsRoute( 'ns.dashboard.home' ) );
    }

    public function updateDatabase()
    {
        return view( 'ns::pages.database-update', [
            'title' => __( 'Database Update' ),
        ] );
    }

    public function postSignIn( SignInRequest $request )
    {
        BeforeSignInEvent::dispatch( $request );

        $attribute = Hook::filter( 'ns.sign-in.attribute', 'username' );

        $attempt = Auth::attempt( [
            $attribute => $request->input( 'username' ),
            'password' => $request->input( 'password' ),
        ] );

        if ( $request->expectsJson() ) {
            return $this->handleJsonRequests( $request, $attempt );
        } else {
            return $this->handleNormalRequests( $request, $attempt );
        }
    }

    public function handleNormalRequests( $request, $attempt )
    {
        if ( $attempt ) {
            /**
             * check if the account is authorized to
             * login
             */
            if ( ! Auth::user()->active ) {
                Auth::logout();

                $validator = Validator::make( $request->all(), [] );
                $validator->errors()->add( 'username', __( 'This account is disabled.' ) );

                return redirect( nsRoute( Hook::filter( 'ns.sign-in.route', 'ns.login' ) ) )->withErrors( $validator );
            }

            return redirect()->intended( Hook::filter( 'ns-login-redirect' ) );
        }

        $validator = Validator::make( $request->all(), [] );
        $validator->errors()->add( 'username', __( 'Unable to find record having that username.' ) );
        $validator->errors()->add( 'password', __( 'Unable to find record having that password.' ) );

        return redirect( nsRoute( Hook::filter( 'ns.sign-in.route', 'ns.login' ) ) )->withErrors( $validator );
    }

    public function handleJsonRequests( $request, $attempt )
    {
        if ( ! $attempt ) {
            throw new NotAllowedException( __( 'Invalid username or password.' ) );
        }

        if ( ! Auth::user()->active ) {
            Auth::logout();
            throw new NotAllowedException( __( 'Unable to login, the provided account is not active.' ) );
        }

        $intended = redirect()->intended()->getTargetUrl();

        event( new AfterSuccessfulLoginEvent( Auth::user() ) );

        return JsonResponse::success(
            message: __( 'You have been successfully connected.' ),
            data: [
                'redirectTo' => Hook::filter( 'ns-login-redirect',
                    ( $intended ) === url( '/' ) ? nsRoute( 'ns.dashboard.home' ) : $intended,
                    redirect()->intended()->getTargetUrl() ? true : false
                ),
            ]
        );
    }

    public function postPasswordLost( PostPasswordLostRequest $request )
    {
        $user = User::where( 'email', $request->input( 'email' ) )->first();

        if ( $user instanceof User ) {
            $user->activation_token = Str::random( 20 );
            $user->activation_expiration = now()->addMinutes( 30 );
            $user->save();

            Mail::to( $user )
                ->queue( new ResetPasswordMail( $user ) );

            return JsonResponse::success(
                message: __( 'The recovery email has been send to your inbox.' ),
                data: [
                    'redirectTo' => route( 'ns.intermediate', [
                        'route' => Hook::filter( 'ns.sign-in.route', 'ns.login' ),
                        'from' => 'ns.password-lost',
                    ] ),
                ]
            );
        }

        throw new NotFoundException( __( 'Unable to find a record matching your entry.' ) );
    }

    /**
     * Process user registration
     */
    public function postSignUp( SignUpRequest $request )
    {
        BeforeSignUpEvent::dispatch( $request );

        try {
            $response = $this->userService->setUser( $request->only( [
                'username',
                'email',
                'password',
            ] ) );

            if ( $request->expectsJson() ) {
                return $response;
            } else {
                return redirect()->route( Hook::filter( 'ns.sign-in.route', 'ns.login' ), [
                    'status' => 'success',
                    'message' => $response[ 'message' ],
                ] );
            }
        } catch ( Exception $exception ) {
            if ( $request->expectsJson() ) {
                throw $exception;
            } else {
                return redirect()->route( 'ns.register' )->withErrors( [
                    'username' => $exception->getMessage(),
                ] );
            }
        }
    }

    public function postNewPassword( PostNewPasswordRequest $request, $userID, $token )
    {
        $user = User::find( $userID );

        if ( ! $user instanceof User ) {
            throw new NotFoundException( __( 'Unable to find the requested user.' ) );
        }

        if ( ! $user->active ) {
            throw new NotAllowedException( __( 'Unable to submit a new password for a non active user.' ) );
        }

        if ( $user->activation_token !== $token ) {
            throw new NotAllowedException( __( 'Unable to proceed, the provided token is not valid.' ) );
        }

        if ( Carbon::parse( $user->activation_expiration )->lessThan( ns()->date->now() ) ) {
            throw new NotAllowedException( __( 'Unable to proceed, the token has expired.' ) );
        }

        $user->password = Hash::make( $request->input( 'password' ) );
        $user->activation_token = null;
        $user->activation_expiration = now()->toDateTimeString();
        $user->save();

        event( new PasswordAfterRecoveredEvent( $user ) );

        return [
            'status' => 'success',
            'message' => __( 'Your password has been updated.' ),
            'data' => [
                'redirectTo' => route( 'ns.intermediate', [
                    'route' => Hook::filter( 'ns.sign-in.route', 'ns.login' ),
                    'from' => 'ns.password-updated',
                ] ),
            ],
        ];
    }
}
