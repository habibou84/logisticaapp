<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Rôles que l'utilisateur connecté peut attribuer (pour les formulaires).
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (Role $role) => ['value' => $role->value, 'label' => $role->label()],
                Role::assignableBy($request->user()),
            ),
        ]);
    }
}
