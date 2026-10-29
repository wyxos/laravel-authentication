<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $heading }}</title></head>
<body style="margin:0;background:#f4f5f7;color:#1f2937;font-family:Arial,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff"><tr><td style="padding:24px">
<h1 style="font-size:24px">{{ $heading }}</h1>
<p>{{ $introduction }}</p>
<dl>
@foreach ($details as $label => $value)
<dt style="font-weight:bold;margin-top:16px">{{ $label }}</dt>
<dd style="margin:4px 0;overflow-wrap:anywhere;white-space:pre-wrap">{{ $value }}</dd>
@endforeach
</dl>
<p>{{ $footer }}</p>
<p>{{ __('Regards,') }}<br>{{ $appName }}</p>
</td></tr></table>
</td></tr></table>
</body>
</html>
