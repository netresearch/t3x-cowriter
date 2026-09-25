// Mock for @typo3/core/ajax/ajax-request.js
// Tests set AjaxRequest.nextPost to a function (url, data, init) => Promise.
const AjaxRequest = vi.fn(function (url) {
    this.url = url;
    this.post = vi.fn((data, init) => AjaxRequest.nextPost(url, data, init));
    this.abort = vi.fn();
    AjaxRequest.instances.push(this);
});
AjaxRequest.instances = [];
AjaxRequest.nextPost = () => Promise.reject(new Error('AjaxRequest.nextPost not set'));

export default AjaxRequest;
