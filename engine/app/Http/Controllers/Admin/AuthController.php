<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use App\Http\Resources\AdminResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Admin-Authentifizierung via Laravel Sanctum (Single-Admin-Modell).
 *   POST /api/v1/admin/login   → Token ausstellen
 *   POST /api/v1/admin/logout  → aktuellen Token widerrufen
 *   GET  /api/v1/admin/me      → angemeldeten Admin abfragen (geschützt)
 */
final class AuthController extends Controller
{
    /** POST /api/v1/admin/login */
    public function login(AdminLoginRequest $request): JsonResponse
    {
        /** @var array{email: string, password: string} $data */
        $data = $request->validated();

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            // Keine Auskunft, ob E-Mail oder Passwort falsch war.
            Log::warning('Admin login failed', ['email' => $data['email']]);

            return response()->json([
                'error' => [
                    'code' => JsonResponse::HTTP_UNAUTHORIZED,
                    'message' => 'Invalid credentials.',
                ],
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $token = $user->createToken('admin')->plainTextToken;

        Log::info('Admin logged in', ['user_id' => $user->id]);

        return AdminResource::make($user)
            ->additional(['token' => $token, 'token_type' => 'Bearer'])
            ->response();
    }

    /** POST /api/v1/admin/logout */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Nur persistente Sanctum-Token tragen delete(); defensiv prüfen.
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        Log::info('Admin logged out', ['user_id' => $user?->getAuthIdentifier()]);

        return response()->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    /** GET /api/v1/admin/me */
    public function me(Request $request): JsonResource
    {
        /** @var User $user */
        $user = $request->user();

        return AdminResource::make($user);
    }
}
