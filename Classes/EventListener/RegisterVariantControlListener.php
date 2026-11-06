<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\EventListener;

use Netresearch\T3Cowriter\Service\Variant\VariantElementReader;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;

/**
 * Adds the "Create an audience variant" field control to the bodytext of the
 * text elements (text, textpic, textmedia).
 *
 * The control goes into the type's columnsOverrides, not onto the shared
 * bodytext column, so no other content type shows it. It does not depend on
 * EXT:contexts: without it the variant is created hidden and without a
 * context. Whether the editor sees it is decided per record when the form is
 * rendered.
 */
#[AsEventListener(identifier: 'cowriter-register-variant-control')]
final readonly class RegisterVariantControlListener
{
    /**
     * Key of the control in `config.fieldControl`.
     */
    public const CONTROL_NAME = 'cowriterVariant';

    /**
     * FormEngine node name (renderType) of the control.
     */
    public const NODE_NAME = 'cowriterVariant';

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
        $content = is_array($tca['tt_content'] ?? null) ? $tca['tt_content'] : null;
        if ($content === null) {
            return $tca;
        }

        $types = is_array($content['types'] ?? null) ? $content['types'] : [];
        foreach (VariantElementReader::SOURCE_CTYPES as $ctype) {
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
