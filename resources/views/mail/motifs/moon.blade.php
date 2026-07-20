{{-- Soft moon and arcs for night out --}}
<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200" data-motif="moon">
  <g fill="none" stroke="{{ $theme['accent'] }}" stroke-opacity="0.18" stroke-width="3">
    <circle cx="140" cy="55" r="28"/>
    <circle cx="152" cy="48" r="28" fill="{{ $theme['softBg'] }}" stroke="none"/>
    <path d="M20 150c40-30 80-30 120 0" stroke-opacity="0.14"/>
    <path d="M30 170c35-22 70-22 105 0" stroke-opacity="0.1"/>
  </g>
  <circle cx="48" cy="42" r="3" fill="{{ $theme['accent'] }}" fill-opacity="0.2"/>
  <circle cx="72" cy="28" r="2" fill="{{ $theme['accent'] }}" fill-opacity="0.16"/>
  <circle cx="90" cy="50" r="2.5" fill="{{ $theme['accent'] }}" fill-opacity="0.18"/>
</svg>
