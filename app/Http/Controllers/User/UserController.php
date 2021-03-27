<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\ApiController;
use App\Mail\UserCreated;
use App\Models\User;
use App\Transformers\UserTransformer;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;

class UserController extends ApiController
{
    public function __construct()
    {
        $this->middleware('client.credentials')->only(['store', 'resend']);
        $this->middleware('transform.input:' . UserTransformer::class)->only(['store', 'update']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $users = User::all();

        return $this->showAll($users);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store()
    {
        $validatedData = request()->validate([
            'name' => [
                'string',
                'required',
                'max:255',
            ],
            'email' => [
                'required',
                'max:255',
                'email',
                'unique:users'
            ],
            'password' => [
                'required',
                'min:6',
                'confirmed'
            ]
        ]);

        $validatedData['password'] = bcrypt($validatedData['password']);
        $validatedData['verified'] = User::UNVERIFIED_USER;
        $validatedData['verification_token'] = User::generateVerificationCode();
        $validatedData['admin'] = User::REGULAR_USER;

        $user = User::create($validatedData);

        return $this->showOne($user, HttpResponse::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(User $user)
    {
        return $this->showOne($user);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(User $user)
    {
        $validatedData = request()->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'max:255',
                'email',
                Rule::unique('users')->ignore($user)
            ],
            'password' => [
                'required',
                'min:6'
            ]
        ]);

        if (request()->has('name')) {
            $user->name = $validatedData['name'];
        }

        if (request()->has('email') && $user->email != $validatedData['email']) {
            $user->verified = User::UNVERIFIED_USER;
            $user->verification_token = User::generateVerificationCode();
            $user->email = $validatedData['email'];
        }

        if (request()->has('password')) {
            $user->password = bcrypt($validatedData['password']);
        }

        if (request()->has('admin')) {
            if (!$user->isVerified()) {
                return $this->errorResponse(
                    'Only verified users can modify the admin field.',
                    HttpResponse::HTTP_CONFLICT
                );
            }

            $user->admin = $validatedData['admin'];
        }

        if (!$user->isDirty()) {
            return $this->errorResponse(
                'You need to specify a different value to update.',
                HttpResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user->save();

        return $this->showOne($user);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(User $user)
    {
        $user->delete();

        return response(null, HttpResponse::HTTP_NO_CONTENT);
    }

    public function verity($token)
    {
        $user = User::where('verification_token', $token)->firstOrFail();

        $user->verified = User::VERIFIED_USER;
        $user->verification_token = null;

        $user->save();

        return $this->showMessage('The account has been successfully verified');
    }

    public function resend(User $user)
    {
        if ($user->isVerified()) {
            return $this->errorResponse('This user is already verified', 409);
        }

        retry(5, function () use ($user) {
            Mail::to($user)->send(new UserCreated($user));
        }, 100);

        return $this->showMessage('The verification token has been resend');
    }
}
