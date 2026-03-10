// Mock for @typo3/backend/notification.js
const Notification = {
    success: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
    error: vi.fn(),
    notice: vi.fn(),
};

export default Notification;
