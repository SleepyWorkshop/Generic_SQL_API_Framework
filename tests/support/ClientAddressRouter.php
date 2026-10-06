<?php

// Test-only router for the PHP built-in server. It presents every request as
// coming from GENERIC_TEST_CLIENT_ADDRESS so that:
// - the public API (target "api") gets a per-run client identity, isolating
//   the shared rate-limit and login-lockout counters in storage/security;
// - the Admin API (target "admin") sees a non-loopback client, so the
//   application gate in LocalAdminMiddleware is exercised over real HTTP.
//   admin/router.php applies its own development-only loopback check first
//   and is bypassed for that reason.
$address = getenv('GENERIC_TEST_CLIENT_ADDRESS');
if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
    http_response_code(500);
    echo 'Test client address is not configured.';
    return true;
}
$_SERVER['REMOTE_ADDR'] = $address;
if (getenv('GENERIC_TEST_ROUTER_TARGET') === 'admin') {
    require __DIR__ . '/../../admin/api.php';
    return true;
}
return require __DIR__ . '/../../api/router.php';
