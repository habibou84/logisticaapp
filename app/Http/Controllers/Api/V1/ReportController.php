<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\Finance\ClientInvoicing;
use App\Services\Orders\AutoAssignChecks;
use App\Services\Reports\DashboardSettings;
use App\Services\Reports\DayReport;
use App\Services\Reports\OrderSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /**
     * Point détaillé sur une période : volumes par état et montants.
     * Un client ne voit que ses courses ; le personnel peut filtrer par marchand.
     */
    public function summary(Request $request, OrderSummary $summary): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'merchant_id' => ['nullable', 'integer'],
            'date_basis' => ['nullable', Rule::in(['created', 'delivery'])],
            // Une seule activité (e-commerce, distribution, MAD)
            'activity' => ['nullable', Rule::enum(Activity::class)],
        ]);

        $orders = Order::query()
            ->visibleTo($request->user())
            ->when(isset($data['merchant_id']) && $request->user()->merchant_id === null, fn ($q) => $q->where('merchant_id', $data['merchant_id']))
            ->when(isset($data['activity']), fn ($q) => $q->where('activity', $data['activity']));

        return response()->json(['data' => $summary->build(
            $orders,
            $request->date('from') ?? today()->startOfMonth(),
            $request->date('to') ?? today(),
            $data['date_basis'] ?? 'created',
        )]);
    }

    /**
     * Point du jour (page Courses) ou d'une période (tableau de bord, 92 jours au plus) : prévues, livrées, argent
     * (e-commerce, droit caisse), plis et accusés (distribution), livreurs en MAD, livreurs de la période.
     */
    public function day(Request $request, DayReport $report): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null, 403);
        $company = $user->company->fresh();
        $data = $request->validate([
            'day' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'activity' => ['nullable', Rule::enum(Activity::class)],
        ]);
        $today = now($company->timezone ?: config('app.timezone'))->toDateString();
        $from = $data['from'] ?? $data['day'] ?? $today;
        $to = isset($data['from']) ? ($data['to'] ?? $from) : $from;
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92, 422, 'Période trop longue (92 jours au plus).');

        return response()->json(['data' => $report->build($company, $from, $user->can('finance.view'), $to, self::activities($user, $data['activity'] ?? null))]);
    }

    /** Réglages qui manquent pour que les courses soient assignées automatiquement (bandeau de la page Courses) */
    public function autoAssignChecks(Request $request, AutoAssignChecks $checks): JsonResponse
    {
        $company = $request->user()->company?->fresh();
        abort_if($company === null || $request->user()->merchant_id !== null, 403);

        return response()->json(['data' => $checks->missing($company)]);
    }

    /**
     * « À faire maintenant » du tableau de bord, en plus des files de courses : lots à échéance proche, contrats MAD
     * sans livreur, factures à émettre ; et les cartes que l'entreprise a masquées.
     */
    public function todo(Request $request, DayReport $report, ClientInvoicing $invoicing, DashboardSettings $settings): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null, 403);
        $activity = $request->validate(['activity' => ['nullable', Rule::enum(Activity::class)]])['activity'] ?? null;
        $company = $user->company->fresh();
        $activities = self::activities($user, $activity);
        $billed = array_values(array_intersect($activities, ['distribution', 'mad']));
        $config = $settings->for($company);

        return response()->json(['data' => [
            'hidden_cards' => $config['hidden_cards'],
            'lot_due_hours' => $config['lot_due_hours'],
            'lots_due' => in_array('distribution', $activities, true) && $user->can('merchants.view') ? $report->lotsDue($company) : [],
            'mad_uncovered' => in_array('mad', $activities, true) && $user->can('merchants.view') ? $report->madUncovered($company) : [],
            'invoices_to_issue' => $billed !== [] && $user->can('invoices.view') ? $invoicing->toIssue($company, $billed) : null,
        ]]);
    }

    /**
     * Activités regardées : celle choisie si l'utilisateur la voit, sinon toutes celles de son périmètre.
     *
     * @return list<string>
     */
    private static function activities(User $user, ?string $chosen): array
    {
        $all = array_values(array_filter(array_map(fn (Activity $a) => $a->value, Activity::cases()), fn (string $a) => $user->seesActivity($a)));

        return $chosen !== null ? array_values(array_intersect($all, [$chosen])) : $all;
    }
}
