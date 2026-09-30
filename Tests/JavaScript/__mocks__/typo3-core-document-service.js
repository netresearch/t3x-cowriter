/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// Mock for @typo3/core/document-service.js
const DocumentService = {
    ready: vi.fn(() => Promise.resolve(document)),
};

export default DocumentService;
