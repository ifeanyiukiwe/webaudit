<?php
// Only allow normal public websites: blocks file://, localhost and private networks (SSRF protection)
function isSafePublicUrl(string $url): bool {
    $parts = parse_url($url);
    if (!$parts) return false;

    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) return false;

    $host = $parts['host'] ?? '';
    if ($host === '') return false;

    $port = $parts['port'] ?? null;
    if ($port !== null && !in_array($port, [80, 443], true)) return false;

    $ips = gethostbynamel($host);          // look up the website's IP addresses
    if (!$ips) return false;

    foreach ($ips as $ip) {
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if (!$isPublic) return false;      // private or reserved address: refuse
    }
    return true;
}