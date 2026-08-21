<?php

it('shows the event code entry form', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Photobooth')
        ->assertSee('name="code"', false);
});
