<?php
/** @var \Icinga\Application\Modules\Module $this */

$this->provideConfigTab(
    'general',
    [
        'title' => $this->translate('General'),
        'label' => $this->translate('General'),
        'url'   => 'config/general'
    ]
);
