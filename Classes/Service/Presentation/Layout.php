<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

/**
 * The content elements a text can be presented as. Both come from
 * bootstrap_package: a content element whose items are inline child records,
 * each with a header and a rich text bodytext.
 *
 * The value is the CType; the tt_content column holding the items carries the
 * name of the item table in bootstrap_package. The child table and its parent
 * pointer are read from that column's TCA, never assumed.
 *
 * @internal
 */
enum Layout: string
{
    case Tabs      = 'tab';
    case Accordion = 'accordion';

    /**
     * The inline column of tt_content that holds the items.
     */
    public function itemColumn(): string
    {
        return 'tx_bootstrappackage_' . $this->value . '_item';
    }
}
