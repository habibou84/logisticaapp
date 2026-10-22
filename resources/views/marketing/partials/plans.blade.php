{{-- Formules de la console (actives, dans l'ordre) --}}
@php($featured = $plans->count() >= 3 ? 1 : null)
@if ($plans->isEmpty())
    <div class="mx-auto max-w-xl rounded-3xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <p class="text-lg font-semibold">Tarifs sur mesure</p>
        <p class="mt-2 text-slate-600">Écrivez-nous : nous vous proposons une formule adaptée au nombre de vos livreurs et de vos courses.</p>
        @if ($demoUrl)
            <a href="{{ $demoUrl }}" class="btn-brand mt-6" rel="noopener" target="_blank">Nous écrire sur WhatsApp</a>
        @endif
    </div>
@else
    <div @class(['mx-auto grid gap-6', 'max-w-sm' => $plans->count() === 1, 'max-w-3xl md:grid-cols-2' => $plans->count() === 2, 'md:grid-cols-3' => $plans->count() >= 3])>
        @foreach ($plans as $i => $plan)
            <div @class([
                'relative flex flex-col rounded-3xl bg-white p-7 shadow-sm ring-1',
                'ring-2 ring-brand-500 shadow-xl shadow-brand-500/10' => $i === $featured,
                'ring-slate-200' => $i !== $featured,
            ])>
                @if ($i === $featured)
                    <span class="absolute -top-3 left-7 rounded-full bg-gradient-to-r from-brand-600 to-flame px-3 py-1 text-xs font-semibold text-white">Le plus choisi</span>
                @endif
                <h3 class="text-lg font-semibold">{{ $plan['name'] }}</h3>
                @if ($plan['description'])
                    <p class="mt-1 text-sm text-slate-600">{{ $plan['description'] }}</p>
                @endif
                <p class="mt-5 flex items-baseline gap-1">
                    <span class="text-4xl font-bold tracking-tight">{{ number_format($plan['price_monthly'], 0, ',', ' ') }}</span>
                    <span class="text-sm font-medium text-slate-500">F CFA / mois</span>
                </p>
                <ul class="mt-6 space-y-3 text-sm text-slate-700">
                    <li class="flex gap-2"><x-marketing.icon name="check" class="mt-0.5 size-4 shrink-0 text-brand-600" />
                        <span><strong>{{ number_format($plan['included_orders'], 0, ',', ' ') }}</strong> courses par mois incluses</span></li>
                    @if ($plan['overage_per_order'] > 0)
                        <li class="flex gap-2"><x-marketing.icon name="check" class="mt-0.5 size-4 shrink-0 text-brand-600" />
                            <span>Puis {{ number_format($plan['overage_per_order'], 0, ',', ' ') }} F par course</span></li>
                    @endif
                    <li class="flex gap-2"><x-marketing.icon name="check" class="mt-0.5 size-4 shrink-0 text-brand-600" />
                        <span>{{ $plan['max_couriers'] ? 'Jusqu\'à '.$plan['max_couriers'].' livreurs actifs' : 'Livreurs illimités' }}</span></li>
                    <li class="flex gap-2"><x-marketing.icon name="check" class="mt-0.5 size-4 shrink-0 text-brand-600" />
                        <span>E-commerçants illimités</span></li>
                    @foreach ($plan['features'] as $feature)
                        <li class="flex gap-2"><x-marketing.icon name="check" class="mt-0.5 size-4 shrink-0 text-brand-600" /><span>{{ $feature }}</span></li>
                    @endforeach
                </ul>
                {{-- Inscriptions fermées : on propose d'écrire plutôt que de s'inscrire --}}
                @if ($signupUrl || $demoUrl)
                    <div class="mt-auto pt-8">
                        <a href="{{ $signupUrl ? $signupUrl.'?formule='.$plan['id'] : $demoUrl }}" @class(['w-full', 'btn-brand' => $i === $featured, 'btn-ghost ring-slate-300 hover:bg-slate-50' => $i !== $featured])
                           @unless ($signupUrl) rel="noopener" target="_blank" @endunless>
                            {{ $signupUrl ? 'Essayer '.($trialDays > 0 ? $trialDays.' jours gratuits' : 'maintenant') : 'Nous contacter' }}
                        </a>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
