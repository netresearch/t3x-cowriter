<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\EventListener;

use Netresearch\T3Cowriter\Service\Presentation\PresentationAccess;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;

/**
 * Adds the "Present as tabs or accordion" field control to the bodytext of
 * the text elements (text, textpic, textmedia), when bootstrap_package's tab
 * or accordion element exists in the compiled TCA.
 *
 * The control goes into the type's columnsOverrides, not onto the shared
 * bodytext column, so no other content type shows it. Whether the editor
 * sees it is decided per record when the form is rendered.
 */
#[AsEventListener(identifier: 'cowriter-register-presentation-control')]
final readonly class RegisterPresentationControlListener
{
    /**
     * Key of the control in `config.fieldControl`.
     */
    public const CONTROL_NAME = 'cowriterPresentation';

    /**
     * FormEngine node name (renderType) of the control.
     */
    public const NODE_NAME = 'cowriterPresentation';

    public function __invoke(AfterTcaCompilationEvent $event): void
    {
        $event->setTca($this->register($event->getTca()));
    }

    /**
     * @param array<array-key, mixed> $tca
     *
     * @return array<array-key, mixed>
     */
    public function register(array $tca): array
    {
        if (PresentationAccess::layoutsInTca($tca) === []) {
            return $tca;
        }

        /** @var array<array-key, mixed> $content layoutsInTca() found tt_content */
        $content = $tca['tt_content'];
        $types   = is_array($content['types'] ?? null) ? $content['types'] : [];

        foreach (PresentationAccess::SOURCE_CTYPES as $ctype) {
            if (!is_array($types[$ctype] ?? null)) {
                continue;
            }

            $type         = $types[$ctype];
            $overrides    = $this->arrayAt($type, 'columnsOverrides');
            $bodytext     = $this->arrayAt($overrides, 'bodytext');
            $config       = $this->arrayAt($bodytext, 'config');
            $fieldControl = $this->arrayAt($config, 'fieldControl');

            // A control an integrator configured himself stays untouched.
            if (isset($fieldControl[self::CONTROL_NAME])) {
                continue;
            }

            $fieldControl[self::CONTROL_NAME] = ['renderType' => self::NODE_NAME];
            $config['fieldControl']           = $fieldControl;
            $bodytext['config']               = $config;
            $overrides['bodytext']            = $bodytext;
            $type['columnsOverrides']         = $overrides;
            $types[$ctype]                    = $type;
        }

        $content['types']  = $types;
        $tca['tt_content'] = $content;

        return $tca;
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @return array<array-key, mixed>
     */
    private function arrayAt(array $array, string $key): array
    {
        return is_array($array[$key] ?? null) ? $array[$key] : [];
    }
}
