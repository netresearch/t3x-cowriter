<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

/*
 * A stand-in for nr-llm's GuardrailHintCollector (ADR-288 there) while the
 * installed nr-llm predates it. Declared only when the real class is absent,
 * so a newer nr-llm is tested with its own collector. It keeps the shape the
 * cowriter reads: capture() returns `result` and `hints`, record() takes
 * objects with public `kind` and `message`.
 */
if (!class_exists(GuardrailHintCollector::class)) {
    final class GuardrailHintCollector
    {
        /** @var list<list<object>> */
        private array $open = [];

        /**
         * @return array{result: mixed, hints: list<object>}
         */
        public function capture(callable $call): array
        {
            $this->open[] = [];
            try {
                $result = $call();
            } finally {
                $hints = array_pop($this->open) ?? [];
            }

            return ['result' => $result, 'hints' => $hints];
        }

        public function record(object ...$hints): void
        {
            foreach (array_keys($this->open) as $index) {
                $this->open[$index] = [...$this->open[$index], ...array_values($hints)];
            }
        }
    }
}
