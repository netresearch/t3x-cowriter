<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

use JsonSerializable;

/**
 * DTO for a single page search result.
 *
 * @internal
 */
final readonly class PageSearchResult implements JsonSerializable
{
    public function __construct(
        public int $uid,
        public string $title,
        public string $slug,
    ) {}

    /**
     * Create from a database row.
     *
     * @param array{uid: int|string, title: string, slug: string|null} $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            uid: (int) $row['uid'],
            title: $row['title'],
            slug: $row['slug'] ?? '',
        );
    }

    /**
     * @return array{uid: int, title: string, slug: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'uid'   => $this->uid,
            'title' => $this->title,
            'slug'  => $this->slug,
        ];
    }
}
