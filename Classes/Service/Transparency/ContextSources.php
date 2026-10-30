<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Transparency;

use Netresearch\T3Cowriter\Service\BackendLabels;

/**
 * What a request sent to the model, by source and count, never the data
 * itself. Counted from the data that went out, after every cut.
 */
final readonly class ContextSources
{
    /**
     * @param bool $pageTitle       the title of the page
     * @param bool $fieldValues     the stored and typed value of the field (single suggestion) or of the reviewed fields (overview)
     * @param int  $pageFields      descriptive fields of the page the record is on (not for a page record)
     * @param int  $recordFields    other text fields of the edited record
     * @param int  $contentElements content elements of the page whose text went out
     * @param bool $parentTitle     the title of the parent page
     * @param int  $siblingTitles   titles of neighbouring pages
     * @param bool $reviewedFields  true for the overview: the values are those of the reviewed fields
     */
    public function __construct(
        public bool $pageTitle = false,
        public bool $fieldValues = false,
        public int $pageFields = 0,
        public int $recordFields = 0,
        public int $contentElements = 0,
        public bool $parentTitle = false,
        public int $siblingTitles = 0,
        public bool $reviewedFields = false,
    ) {}

    /**
     * The sources as a plain list in the editor's language, e.g.
     * "Page title", "12 page fields", "Content of 5 elements".
     *
     * @return list<string>
     */
    public function labels(BackendLabels $labels): array
    {
        $list = [];
        if ($this->pageTitle) {
            $list[] = $labels->get('about.source.pageTitle');
        }

        if ($this->fieldValues) {
            $list[] = $labels->get($this->reviewedFields ? 'about.source.reviewedValues' : 'about.source.fieldValue');
        }

        foreach ([
            'pageFields'      => $this->pageFields,
            'recordFields'    => $this->recordFields,
            'contentElements' => $this->contentElements,
        ] as $key => $count) {
            if ($count > 0) {
                $list[] = $this->counted($labels, 'about.source.' . $key, $count);
            }
        }

        if ($this->parentTitle) {
            $list[] = $labels->get('about.source.parentTitle');
        }

        if ($this->siblingTitles > 0) {
            $list[] = $this->counted($labels, 'about.source.siblingTitles', $this->siblingTitles);
        }

        return $list;
    }

    /**
     * The singular label for one ("1 page field"), the plural label with the
     * count otherwise.
     */
    private function counted(BackendLabels $labels, string $key, int $count): string
    {
        return $count === 1 ? $labels->get($key . 'Singular') : $labels->get($key, $count);
    }
}
