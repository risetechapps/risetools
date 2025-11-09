@extends('view-suite::emails.default.layout')

@section('content')

    @include('view-suite::emails.default.header_subject', [
    'headerSubject' => $subject_message
    ])

    <div align="center" class="img-container center autowidth" style="padding-right: 0;padding-left: 0;">
        <img align="center" alt="Image" border="0" class="center autowidth" src="https://i.imgur.com/ADTG9En.png"
             style="text-decoration: none; -ms-interpolation-mode: bicubic; height: auto; border: 0; width: 100%;
            max-width: 158px; display: block;" title="Image" width="158"/>
    </div>

    @isset($line)
        @include('view-suite::emails.default.message_line', ['lineMessage' => $line])
    @endisset

    @isset($line_header)
        @foreach($line_header as $line)
            @include('view-suite::emails.default.message_line', ['lineMessage' => $line])
        @endforeach
    @endisset

    @if(!empty($tables))
        @foreach($tables as $table)
            @include('view-suite::emails.default.table', ['table' => $table])
        @endforeach
    @endif

    @if(!empty($lists))
        @foreach($lists as $list)
            @include('view-suite::emails.default.list', ['list' => $list])
        @endforeach
    @endif

    @isset($action)
        @include('view-suite::emails.default.button', ['urlButton' => $action['url'], 'textButton' => $action['text']])
    @endif

    @isset($line_footer)
        @foreach($line_footer as $line)
            @include('view-suite::emails.default.message_line', ['lineMessage' => $line])
        @endforeach
    @endisset

    @isset($signature)
        @if(is_array($signature))
            @foreach($signature as $s)
                @include('view-suite::emails.default.signature', ['signature' => $s])
            @endforeach
        @endif
    @endisset

@endsection
