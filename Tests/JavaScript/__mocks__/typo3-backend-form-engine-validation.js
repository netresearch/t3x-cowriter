/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// Mock for @typo3/backend/form-engine-validation.js
const FormEngineValidation = {
    validateField: vi.fn(),
};

export default FormEngineValidation;
