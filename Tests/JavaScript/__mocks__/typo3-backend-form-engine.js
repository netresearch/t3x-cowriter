/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// Mock for @typo3/backend/form-engine.js
const FormEngine = {
    markFieldAsChanged: vi.fn(),
};

export default FormEngine;
