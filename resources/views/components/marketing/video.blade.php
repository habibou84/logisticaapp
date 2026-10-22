@props(['name', 'title', 'vertical' => false, 'large' => false])

{{-- Vidéo de démonstration (public/marketing/videos) : chargée seulement au clic sur lecture --}}
<figure {{ $attributes->class([
    'overflow-hidden bg-slate-950 shadow-xl ring-1 ring-slate-900/10',
    'mx-auto w-full rounded-[1.75rem]' => $vertical,
    'max-w-[17rem]' => $vertical && ! $large,
    'max-w-[20rem]' => $vertical && $large,
    'w-full rounded-2xl' => ! $vertical,
]) }}>
    <video
        class="block w-full"
        src="/marketing/videos/{{ $name }}.mp4"
        poster="/marketing/videos/{{ $name }}.jpg"
        width="{{ $vertical ? 1080 : 1920 }}"
        height="{{ $vertical ? 1920 : 1080 }}"
        controls
        playsinline
        preload="none"
        aria-label="Vidéo : {{ $title }}"
    ></video>
</figure>
