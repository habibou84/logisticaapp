@props(['name', 'title'])

{{-- Vidéo en boucle, sans le son, lancée seule (haut de page) : verticale sur téléphone, horizontale sur ordinateur --}}
<div {{ $attributes->class('relative') }}>
    <video
        class="mx-auto block w-full max-w-[22rem] rounded-[2rem] shadow-2xl ring-1 ring-white/10 sm:hidden"
        poster="/marketing/videos/{{ $name }}-v.jpg"
        width="1080" height="1920"
        autoplay muted loop playsinline preload="metadata"
        aria-label="Vidéo : {{ $title }}"
    ><source src="/marketing/videos/{{ $name }}-v.mp4" type="video/mp4"></video>
    <video
        class="hidden w-full rounded-2xl shadow-2xl ring-1 ring-white/10 sm:block"
        poster="/marketing/videos/{{ $name }}-w.jpg"
        width="1920" height="1080"
        autoplay muted loop playsinline preload="metadata"
        aria-label="Vidéo : {{ $title }}"
    ><source src="/marketing/videos/{{ $name }}-w.mp4" type="video/mp4"></video>
</div>
