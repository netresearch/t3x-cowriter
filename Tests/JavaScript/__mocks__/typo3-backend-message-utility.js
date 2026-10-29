/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// Mock for @typo3/backend/utility/message-utility.js
export const MessageUtility = {
    send: vi.fn(),
    verifyOrigin: vi.fn(() => true),
    getOrigin: vi.fn(() => window.origin),
};
