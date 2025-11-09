<table align="center" cellpadding="0" cellspacing="0"
       class="social_table" role="presentation"
       style="table-layout: fixed; vertical-align: top; border-spacing: 0; border-collapse: collapse;
        mso-table-tspace: 0; mso-table-rspace: 0; mso-table-bspace: 0; mso-table-lspace: 0;" valign="top">
    <tbody>
    <tr align="center" style="vertical-align: top; display: inline-block; text-align: center;" valign="top">

        @isset($social_network)

            @isset($social_network['facebook'])
                <td style="word-break: break-word; vertical-align: top; padding-bottom: 0; padding-right: 7.5px;
                    padding-left: 7.5px;" valign="top">
                    <a href="{{$social_network['facebook']}}" target="_blank">
                        <img alt="Facebook" height="32" src="https://imgur.com/to1Y9jj"
                             style="text-decoration: none;-ms-interpolation-mode: bicubic; height: auto; border: 0;
                             display: block;" title="Facebook" width="32"/>
                    </a>
                </td>
            @endisset

            @isset($social_network['x'])
                <td style="word-break: break-word; vertical-align: top; padding-bottom: 0; padding-right: 7.5px;
                    padding-left: 7.5px;" valign="top">
                    <a href="{{$social_network['x']}}" target="_blank">
                        <img alt="X" height="32" src=https://imgur.com/to1Y9jj.png"
                             style="text-decoration: none;-ms-interpolation-mode: bicubic; height: auto; border: 0;
                             display: block;" title="X" width="32"/>
                    </a>
                </td>
            @endisset

            @isset($social_network['instagram'])
                <td style="word-break: break-word; vertical-align: top; padding-bottom: 0; padding-right: 7.5px;
                    padding-left: 7.5px;" valign="top">
                    <a href="{{$social_network['instagram']}}" target="_blank">
                        <img alt="Instagram" height="32" src="https://imgur.com/yH7dLSn.png"
                             style="text-decoration: none;-ms-interpolation-mode: bicubic; height: auto; border: 0;
                             display: block;" title="Instagram" width="32"/>
                    </a>
                </td>
            @endisset

            @isset($social_network['facebook'])
                <td style="word-break: break-word; vertical-align: top; padding-bottom: 0; padding-right: 7.5px;
                    padding-left: 7.5px;" valign="top">
                    <a href="{{$social_network['facebook']}}" target="_blank">
                        <img alt="Facebook" height="32" src="https://imgur.com/akurIYU.png"
                             style="text-decoration: none;-ms-interpolation-mode: bicubic; height: auto; border: 0;
                             display: block;" title="Facebook" width="32"/>
                    </a>
                </td>
            @endisset

            @isset($social_network['pinterest'])
                <td style="word-break: break-word; vertical-align: top; padding-bottom: 0; padding-right: 7.5px;
                    padding-left: 7.5px;" valign="top">
                    <a href="{{$social_network['pinterest']}}" target="_blank">
                        <img alt="Pinterest" height="32" src="https://imgur.com/4Mluzhl.png"
                             style="text-decoration: none;-ms-interpolation-mode: bicubic; height: auto; border: 0;
                             display: block;" title="Pinterest" width="32"/>
                    </a>
                </td>
            @endisset

            @isset($social_network['linkedin'])
                <td style="word-break: break-word; vertical-align: top; padding-bottom: 0; padding-right: 7.5px;
                    padding-left: 7.5px;" valign="top">
                    <a href="{{$social_network['linkedin']}}" target="_blank">
                        <img alt="Linkedin" height="32" src="https://imgur.com/1P6vGIn.png"
                             style="text-decoration: none;-ms-interpolation-mode: bicubic; height: auto; border: 0;
                             display: block;" title="Linkedin" width="32"/>
                    </a>
                </td>
            @endisset

            @isset($social_network['youtube'])
                <td style="word-break: break-word; vertical-align: top; padding-bottom: 0; padding-right: 7.5px;
                    padding-left: 7.5px;" valign="top">
                    <a href="{{$social_network['youtube']}}" target="_blank">
                        <img alt="Youtube" height="32" src="https://imgur.com/CADbsDF.png"
                             style="text-decoration: none;-ms-interpolation-mode: bicubic; height: auto; border: 0;
                             display: block;" title="Youtube" width="32"/>
                    </a>
                </td>
            @endisset

        @endisset
    </tr>
    </tbody>
</table>
