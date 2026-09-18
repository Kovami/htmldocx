<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRules(['@PER-CS3.0' => true])
    ->setFinder(Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests']));
