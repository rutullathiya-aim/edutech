<?php

test('security headers are present on application responses', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('token pages send no-referrer policy to prevent leaking tokens', function () {
    $response = $this->get('/reset-password/sample-plain-token');

    $response->assertHeader('Referrer-Policy', 'no-referrer');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
});
