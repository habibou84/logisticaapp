@extends('marketing.layout')

@section('title', 'Page introuvable · Jibiat')

@section('content')
    <section class="bg-slate-950 pt-40 pb-32 text-center text-white">
        <div class="mx-auto max-w-xl px-4">
            <p class="font-semibold text-brand-400">Erreur 404</p>
            <h1 class="mt-3 text-4xl font-bold tracking-tight">Cette page n'existe pas</h1>
            <p class="mt-4 text-slate-300">Vous cherchez l'espace de votre entreprise de livraison ? Ouvrez l'adresse qu'elle vous a donnée, par exemple <span class="font-mono text-white">votre-entreprise.{{ $domain }}</span>.</p>
            <a href="/" class="btn-brand mt-8">Retour à l'accueil</a>
        </div>
    </section>
@endsection
