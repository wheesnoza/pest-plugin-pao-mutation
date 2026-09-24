<?php

use function Wheesnoza\PestPluginPaoMutation\example;

it('may be accessed on the `$this` closure', function () {
    $this->example('foo');
});

it('may be accessed as function', function () {
    example('foo');
});
