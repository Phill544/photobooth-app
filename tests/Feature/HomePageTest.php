<?php

it('shows the event code entry form', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Quikbooth')
        ->assertSee('name="code"', false);
});
