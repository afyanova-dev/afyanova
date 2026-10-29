@php($expiredLabel = \Illuminate\Support\Carbon::parse($expiredAt)->format('d M Y, H:i'))

<p>A setup invitation for <strong>{{ $inviteeName }}</strong> ({{ $inviteeEmail }}) at {{ $facilityName }} expired on {{ $expiredLabel }}.</p>
<p>The staff record is still in the facility directory, but the account has not been activated. Open the staff profile to resend the invitation or revoke it before inviting a different email.</p>
<p><a href="{{ $actionUrl }}">Open the invitation in AfyaNova</a></p>
<p>This is an automated HR operations alert. Replying to this message does not resend the invitation.</p>
