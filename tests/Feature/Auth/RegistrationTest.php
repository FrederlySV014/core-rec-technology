<?php

use Laravel\Fortify\Features;

test('public registration feature is disabled in fortify configuration', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();
});

test('public registration screen is inaccessible and returns 404', function () {
    $response = $this->get('/register');

    $response->assertNotFound();
});

test('public registration direct post request is rejected and returns 404', function () {
    $response = $this->post('/register', [
        'name' => 'Intruder User',
        'email' => 'intruder@example.com',
        'password' => 'password123#',
        'password_confirmation' => 'password123#',
    ]);

    $response->assertNotFound();
    $this->assertGuest();
});
