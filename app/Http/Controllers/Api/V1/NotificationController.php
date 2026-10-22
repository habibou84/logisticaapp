<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->limit(50)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                ...$n->data,
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
            ]);

        return response()->json([
            'data' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Marque comme lues les notifications indiquées, ou toutes.
     */
    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['string'],
        ]);

        $request->user()->unreadNotifications()
            ->when(isset($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->update(['read_at' => now()]);

        return response()->json(['unread_count' => $request->user()->unreadNotifications()->count()]);
    }
}
