<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle user login request.
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors());
        }

        $credentials = $request->only('email', 'password');

        if (!Auth::attempt($credentials)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'Invalid email or password.'
                ]
            ], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'slug' => $user->role->slug,
                    ],
                    'mess' => $user->mess ? [
                        'id' => $user->mess->id,
                        'name' => $user->mess->name,
                    ] : null,
                    'status' => $user->status,
                ],
                'token' => $token,
                'expires_in' => config('sanctum.expiration', 525600) // 15 days in minutes
            ],
            'message' => 'Login successful'
        ]);
    }

    /**
     * Handle user registration request.
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'phone' => 'required|string|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'mess_name' => 'required|string|max:255',
            'mess_address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors());
        }

        DB::beginTransaction();
        try {
            // Create mess if it doesn't exist
            $mess = null;
            if ($request->mess_name) {
                $mess = \App\Models\Mess::firstOrCreate([
                    'name' => $request->mess_name,
                    'address' => $request->mess_address,
                    'payment_cycle' => 'monthly',
                ]);
            }

            // Create member role for new user
            $memberRole = Role::where('slug', 'member')->first();

            // Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'role_id' => $memberRole->id,
                'mess_id' => $mess ? $mess->id : null,
                'status' => 'active',
            ]);

            // Create member record
            if ($mess) {
                \App\Models\Member::create([
                    'user_id' => $user->id,
                    'mess_id' => $mess->id,
                    'joining_date' => now()->toDateString(),
                    'status' => 'active',
                ]);
            }

            DB::commit();

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'role' => [
                            'id' => $user->role->id,
                            'name' => $user->role->name,
                            'slug' => $user->role->slug,
                        ],
                        'mess' => $user->mess ? [
                            'id' => $user->mess->id,
                            'name' => $user->mess->name,
                        ] : null,
                        'status' => $user->status,
                    ],
                    'token' => $token,
                    'expires_in' => config('sanctum.expiration', 525600)
                ],
                'message' => 'Registration successful'
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REGISTRATION_FAILED',
                    'message' => 'Registration failed. Please try again.',
                    'details' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Handle forgot password request.
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors());
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'USER_NOT_FOUND',
                    'message' => 'User not found with this email.'
                ]
            ], 404);
        }

        // Generate OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store OTP in cache for 15 minutes
        \Cache::put('password_reset_otp_' . $request->email, $otp, now()->addMinutes(15));

        // TODO: Send OTP via SMS/Email
        // This would integrate with SMS gateway and email service

        return response()->json([
            'success' => true,
            'message' => 'Password reset OTP sent to your email/phone.',
            'otp' => $otp // Only for development, remove in production
        ]);
    }

    /**
     * Handle password reset with OTP.
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users',
            'otp' => 'required|string|size:6',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors());
        }

        $cachedOtp = \Cache::get('password_reset_otp_' . $request->email);

        if (!$cachedOtp || $cachedOtp !== $request->otp) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_OTP',
                    'message' => 'Invalid or expired OTP.'
                ]
            ], 400);
        }

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $user->update([
                'password' => Hash::make($request->password),
            ]);
        }

        // Clear OTP from cache
        \Cache::forget('password_reset_otp_' . $request->email);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successful.'
        ]);
    }

    /**
     * Handle token refresh request.
     */
    public function refresh(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Authentication required.'
                ]
            ], 401);
        }

        // Revoke old tokens
        $user->tokens()->delete();

        // Create new token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'expires_in' => config('sanctum.expiration', 525600)
            ],
            'message' => 'Token refreshed successfully'
        ]);
    }

    /**
     * Handle user logout request.
     */
    public function logout(Request $request)
    {
        $user = Auth::user();

        if ($user) {
            // Revoke all tokens for the user
            $user->tokens()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }
}
