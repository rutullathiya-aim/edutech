<?php

test('security headers are present on application responses', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('token pages send no-referrer policy to prevent leaking tokens', function () {
    foreach (['/reset-password/sample-plain-token', '/invitations/sample-plain-token', '/invitations/accept'] as $uri) {
        $response = $this->get($uri);

        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }
});
