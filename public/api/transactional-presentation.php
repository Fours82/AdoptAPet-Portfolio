<?php
declare(strict_types=1);

function formatTransactionalDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable) {
        return $value;
    }

    return $date->format('j M Y, g:i A');
}

function transactionalEmailEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function transactionalEmailLayout(string $heading, string $content, string $preheader = ''): string
{
    $safeHeading = transactionalEmailEscape($heading);
    $safePreheader = transactionalEmailEscape($preheader);

    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $safeHeading . '</title>'
        . '<style>@media only screen and (max-width:640px){.email-shell{width:100%!important}.email-pad{padding-left:22px!important;padding-right:22px!important}.detail-label,.detail-value{display:block!important;width:auto!important;padding-left:0!important;padding-right:0!important}.detail-label{padding-bottom:2px!important}.detail-value{padding-top:0!important}}</style>'
        . '</head><body style="margin:0;padding:0;background-color:#fff9ed;color:#3d3027;font-family:Arial,Helvetica,sans-serif;">'
        . ($safePreheader !== ''
            ? '<div style="display:none;font-size:1px;color:#fff9ed;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">' . $safePreheader . '</div>'
            : '')
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background-color:#fff9ed;">'
        . '<tr><td align="center" style="padding:28px 12px;">'
        . '<table role="presentation" class="email-shell" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background-color:#ffffff;border:1px solid #eed8bc;border-top:4px solid #e8893a;">'
        . '<tr><td class="email-pad" style="padding:22px 34px 18px 34px;border-bottom:1px solid #eed8bc;">'
        . '<img src="cid:adoptapet-logo" width="150" alt="AdoptAPet" style="display:block;width:150px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">'
        . '<div style="padding-top:4px;font-size:12px;line-height:18px;color:#75665b;">Helping rescued pets find loving homes</div>'
        . '</td></tr>'
        . '<tr><td class="email-pad" style="padding:30px 34px 32px 34px;">'
        . '<h1 style="margin:0 0 20px 0;font-size:26px;line-height:34px;color:#3d3027;font-weight:700;">' . $safeHeading . '</h1>'
        . $content
        . '</td></tr>'
        . '<tr><td class="email-pad" style="padding:18px 34px;border-top:1px solid #eed8bc;background-color:#fff9ed;font-size:12px;line-height:18px;color:#75665b;">'
        . '<strong style="color:#3d3027;">AdoptAPet</strong><br>Web-Based Pet Adoption System<br>'
        . 'This is an automated service message from AdoptAPet.'
        . '</td></tr></table>'
        . '</td></tr></table></body></html>';
}

/**
 * @param array<int, array{0:string, 1:string}> $rows
 */
function transactionalEmailDetails(array $rows): string
{
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:22px 0;border:1px solid #eed8bc;background-color:#fffaf3;">';
    foreach ($rows as $index => [$label, $value]) {
        $border = $index === 0 ? '' : 'border-top:1px solid #eed8bc;';
        $html .= '<tr>'
            . '<td class="detail-label" width="38%" valign="top" style="' . $border . 'padding:11px 14px;font-size:13px;line-height:19px;font-weight:700;color:#75665b;">' . transactionalEmailEscape($label) . '</td>'
            . '<td class="detail-value" width="62%" valign="top" style="' . $border . 'padding:11px 14px;font-size:14px;line-height:20px;color:#3d3027;overflow-wrap:anywhere;word-break:break-word;">' . transactionalEmailEscape($value) . '</td>'
            . '</tr>';
    }

    return $html . '</table>';
}

function transactionalEmailCodeBlock(string $code): string
{
    return '<div style="margin:22px 0;padding:19px 16px;border:1px solid #eed8bc;border-left:4px solid #e8893a;background-color:#fff9ed;text-align:center;">'
        . '<span style="font-family:Courier New,Courier,monospace;font-size:30px;line-height:36px;font-weight:700;letter-spacing:6px;color:#3d3027;">'
        . transactionalEmailEscape($code)
        . '</span></div>';
}

function transactionalEmailNotice(string $message, string $tone = 'neutral'): string
{
    $borderColor = $tone === 'security' ? '#d87526' : '#e8893a';

    return '<div style="margin:22px 0 0 0;padding:14px 16px;border-left:4px solid ' . $borderColor . ';background-color:#fff9ed;font-size:14px;line-height:22px;color:#3d3027;">'
        . transactionalEmailEscape($message)
        . '</div>';
}
