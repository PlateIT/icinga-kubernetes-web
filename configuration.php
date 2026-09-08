<?php

/** @var \Icinga\Application\Modules\Module $this */

$section = $this->menuSection('Kubernetes', [
    'icon' => 'globe',
    'url'  => 'kubernetes/dashboard'
]);
$section->add(N_('Resources'), [
    'description' => $this->translate('Live Kubernetes and OpenShift resources'),
    'url'         => 'kubernetes/resources',
    'priority'    => 10
]);
$section->add(N_('Problems'), [
    'url' => 'kubernetes/resources',
    'urlParameters' => ['state' => 'problem'],
    'priority' => 5
]);
$this->providePermission(
    'kubernetes/resources/show',
    $this->translate('Allow access to Kubernetes resources through the module API')
);
$this->providePermission(
    'kubernetes/resources/logs',
    $this->translate('Allow loading current Kubernetes pod logs through the module API')
);
$this->provideRestriction(
    'kubernetes/filter/resources',
    $this->translate('Restrict resources visible through the Kubernetes module')
);
$this->provideCssFile('common.less');
