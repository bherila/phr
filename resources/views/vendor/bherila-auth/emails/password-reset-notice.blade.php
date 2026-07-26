@component('mail::message')
# Your password was changed

Hi {{ data_get($user, config('bherila-auth.users.name_attribute', 'name'), 'there') }},

This is a confirmation that the password for your {{ $appName }} account was changed.

If you did not make this change, contact support immediately.

Thanks,<br>
{{ $appName }}
@endcomponent
