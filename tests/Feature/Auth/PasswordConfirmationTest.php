<?php

use App\Models\User;

test('confirm password screen can be rendered', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('password.confirm'))
        ->assertOk();
});
