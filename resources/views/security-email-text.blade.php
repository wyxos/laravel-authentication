{{-- Literal values in the text/plain MIME part; this view must not be used as HTML. --}}
{!! $heading !!}

{!! $introduction !!}

@foreach ($details as $label => $value)
{!! $label !!}{{ str_ends_with($label, ':') ? ' ' : ': ' }}{!! $value !!}
@endforeach

{!! $footer !!}

{!! __('Regards,') !!}
{!! $appName !!}
