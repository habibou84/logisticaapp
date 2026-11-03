<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\ClientReport;
use App\Services\Reports\ClientReports;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rapports mensuels des clients sous contrat : l'équipe relit les brouillons et les envoie ; le client retrouve les
 * rapports envoyés dans son espace (Mes rapports).
 */
class ClientReportController extends Controller
{
    public function __construct(private readonly ClientReports $reports) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $query = ClientReport::query()->with('merchant:id,business_name')->orderByDesc('period')->orderBy('id');
        if ($user->merchant_id !== null) {
            abort_unless($user->isMerchantLead(), 403);
            $query->where('merchant_id', $user->merchant_id)->where('status', 'sent');
        } else {
            abort_unless($user->can(Permission::MerchantsView->value), 403);
            $query->when($request->filled('period'), fn ($q) => $q->whereDate('period', $request->string('period').'-01'));
        }

        return response()->json(['data' => $query->limit(300)->get()->map(fn (ClientReport $r) => $this->present($r, false))->values()]);
    }

    public function show(Request $request, ClientReport $clientReport): JsonResponse
    {
        $this->authorizeView($request, $clientReport);

        return response()->json(['data' => $this->present($clientReport, true)]);
    }

    /** Brouillons du mois (AAAA-MM), recalculés (les rapports envoyés ne changent pas) */
    public function generate(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return response()->json(['data' => ['count' => $this->reports->generate($request->user()->company, CarbonImmutable::parse($data['month'].'-01'))]]);
    }

    /** Mot de l'équipe sur un brouillon */
    public function update(Request $request, ClientReport $clientReport): JsonResponse
    {
        $this->authorizeManage($request);
        abort_if($clientReport->status === 'sent', 422, 'Rapport déjà envoyé.');
        $clientReport->update($request->validate(['note' => ['nullable', 'string', 'max:2000']]));

        return response()->json(['data' => $this->present($clientReport->fresh(), true)]);
    }

    public function send(Request $request, ClientReport $clientReport): JsonResponse
    {
        $this->authorizeManage($request);
        abort_if($clientReport->status === 'sent', 422, 'Rapport déjà envoyé.');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        return response()->json(['data' => $this->present($this->reports->send($clientReport, $request->user(), $note), true)]);
    }

    private function authorizeView(Request $request, ClientReport $report): void
    {
        $user = $request->user();
        if ($user->merchant_id !== null) {
            abort_unless($user->isMerchantLead() && $report->merchant_id === $user->merchant_id && $report->status === 'sent', 404);

            return;
        }
        abort_unless($user->can(Permission::MerchantsView->value), 403);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->merchant_id === null && $request->user()->can(Permission::InvoicesManage->value), 403);
    }

    /** @return array<string, mixed> */
    private function present(ClientReport $r, bool $full): array
    {
        return [
            'id' => $r->id,
            'merchant' => ['id' => $r->merchant_id, 'name' => $r->merchant?->business_name ?? $r->data['merchant'] ?? null],
            'period' => $r->period->format('Y-m'),
            'label' => $r->data['label'] ?? $r->period->format('m/Y'),
            'status' => $r->status,
            'sent_at' => $r->sent_at?->toIso8601String(),
            'note' => $r->note,
            'summary' => collect($r->data)->only(['delivered', 'units_delivered', 'on_time_rate', 'failures'])->all(),
            ...($full ? ['data' => $r->data] : []),
        ];
    }
}
