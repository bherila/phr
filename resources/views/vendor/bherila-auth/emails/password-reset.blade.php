@component('mail::message')
# Reset your {{ $appName }} password

Hi {{ data_get($user, config('bherila-auth.users.name_attribute', 'name'), 'there') }},

Use the button below to reset your password.

@component('mail::button', ['url' => $resetUrl])
Reset Password
@endcomponent

If you did not request a password reset, you can ignore this email.

Thanks,<br>
{{ $appName }}
@endcomponent
