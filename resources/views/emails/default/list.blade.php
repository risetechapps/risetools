@php($isOrdered = ($list['type'] ?? 'unordered') === 'ordered')
<{{ $isOrdered ? 'ol' : 'ul' }} style="margin: 20px 40px; padding-left: 20px; font-family: Trebuchet MS, Lucida Grande, Lucida Sans Unicode, Lucida Sans, Tahoma, sans-serif; color: #555555;">
    @foreach($list['items'] as $item)
        <li style="margin-bottom: 6px; font-size: 14px;">{{ $item }}</li>
    @endforeach
</{{ $isOrdered ? 'ol' : 'ul' }}>
