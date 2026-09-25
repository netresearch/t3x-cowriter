// Mock for @typo3/core/document-service.js
const DocumentService = {
    ready: vi.fn(() => Promise.resolve(document)),
};

export default DocumentService;
