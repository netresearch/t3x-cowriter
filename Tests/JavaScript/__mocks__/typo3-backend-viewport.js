/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// Mock for @typo3/backend/viewport.js: records the URLs the content frame
// is asked to load.
const Viewport = {
    ContentContainer: {
        setUrl: vi.fn(() => Promise.resolve()),
    },
};

export default Viewport;
