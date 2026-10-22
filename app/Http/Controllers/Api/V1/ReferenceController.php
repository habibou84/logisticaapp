<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\IncidentReasonResource;
use App\Http\Resources\V1\RecipientResource;
use App\Models\IncidentReason;
use App\Models\Recipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReferenceController extends Controller
{
    /**
     * Motifs d'incident disponibles (communs + propres à l'entreprise).
     */
    public function incidentReasons(Request $request): AnonymousResourceCollection
    {
        return IncidentReasonResource::collection(
            IncidentReason::availableTo($request->user()->company_id)->orderBy('sort_order')->get()
        );
    }

    /**
     * Carnet de destinataires (autocomplétion lors de la saisie d'une course).
     */
    public function recipients(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $request->validate([
            'merchant_id' => [$user->merchant_id === null ? 'required' : 'prohibited', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        abort_if($user->merchant_id === null && ! $user->can('orders.create'), 403);

        $recipients = Recipient::query()
            ->where('merchant_id', $user->merchant_id ?? $request->integer('merchant_id'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.mb_strtolower($request->string('search')).'%';
                $q->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])->orWhere('phone', 'like', $term));
            })
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();

        return RecipientResource::collection($recipients);
    }
}
