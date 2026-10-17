<?php

test('guests are redirected from root to login', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login', absolute: false));
});
