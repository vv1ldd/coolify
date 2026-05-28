<x-emails.layout>
A team invitation artifact exists for "{{ $team }}" on "{{ config('app.name') }}".

This email is only a discovery notice. It does not grant membership or carry authority.

Please [open {{ config('app.name') }}]({{ $discovery_link ?? route('login') }}) and use SL1 Connect to evaluate the invitation with your identity proof.

If you have any questions, please contact the team owner.<br><br>

If you did not expect this discovery notice, please ignore this email.
</x-emails.layout>
