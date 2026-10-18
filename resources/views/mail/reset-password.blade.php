@component('mail::message')
# Choose a new password

We received a request to reset the password for **{{ $email }}**.

Enter this code in the PLNR app, or on the website. It works once and expires in 60 minutes.

<x-mail::panel>
<span style="font-size: 28px; letter-spacing: 6px; font-weight: 700;">{{ $code }}</span>
</x-mail::panel>

If you did not ask for this, you can ignore this email.

@include('mail.partials.store-badges')
@endcomponent
