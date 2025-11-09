<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
       style="margin: 20px 0; border-collapse: collapse;">
    <thead>
    <tr>
        @foreach($table['headers'] as $header)
            <th align="left"
                style="padding:10px 15px;background-color:#f5f7fb;color:#2f5496;
           font-family:Trebuchet MS,Lucida Grande,Lucida Sans Unicode,Lucida Sans,Tahoma,sans-serif;
           font-size:14px;border:1px solid #dbe2f1;
           white-space:nowrap;word-break:keep-all;">
                {{ $header }}
            </th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @foreach($table['rows'] as $row)
        <tr>
            @foreach($row as $column)
                <td  style="padding:10px 15px;background-color:#f5f7fb;color:#2f5496;
           font-family:Trebuchet MS,Lucida Grande,Lucida Sans Unicode,Lucida Sans,Tahoma,sans-serif;
           font-size:14px;border:1px solid #dbe2f1;
           ">
                    {{ $column }}
                </td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
