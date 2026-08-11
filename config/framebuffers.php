<?php

use ScrapyardIO\Tubes\Framebuffers\DirtyRegionsBuffer;
use ScrapyardIO\Tubes\Framebuffers\FullFramebuffer;
use ScrapyardIO\Tubes\Framebuffers\PageSegmentBuffer;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Framebuffer Driver
    |--------------------------------------------------------------------------
    |
    | Used by Framebuffer::make() when no driver name is passed. Built-ins are
    | full, dirty, and page. Companions publish config/framebuffers/<slug>.php.
    |
    */

    'default' => env('FRAMEBUFFER_DRIVER', 'full'),

    /*
    |--------------------------------------------------------------------------
    | Driver Registry
    |--------------------------------------------------------------------------
    |
    | kind: managed|deferred
    | class: concrete with static sized()
    | extension: optional PHP extension that must be loaded before create()
    |
    */

    'drivers' => [
        'full' => [
            'kind' => 'managed',
            'class' => FullFramebuffer::class,
        ],
        'dirty' => [
            'kind' => 'managed',
            'class' => DirtyRegionsBuffer::class,
        ],
        'page' => [
            'kind' => 'managed',
            'class' => PageSegmentBuffer::class,
        ],
    ],

];
