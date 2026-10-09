@component('mail::message')
# Choose a new password

We received a request to reset the password for **{{ $email }}**. This link works once and expires in 60 minutes.

<x-mail::button :url="$resetUrl" :color="$theme['slug']">
Choose a new password
</x-mail::button>

Or copy this link into your browser:
[{{ $resetUrl }}]({{ $resetUrl }})

If you did not ask for this, you can ignore this email.
@endcomponent
