# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 3.x     | :white_check_mark: |
| 2.x     | :x:                |
| 1.x     | :x:                |

## Reporting a Vulnerability

We take the security of this extension seriously. If you discover a security
vulnerability, please report it responsibly.

### How to Report

**DO NOT** create a public GitHub issue for security vulnerabilities.

Instead, please report security vulnerabilities via email to:
**[GitHub Security Advisories](https://github.com/netresearch/t3x-cowriter/security/advisories/new)**

Include the following information:
- Type of vulnerability
- Full path of the affected source file(s)
- Location of the affected source code (tag/branch/commit or direct URL)
- Step-by-step instructions to reproduce the issue
- Proof-of-concept or exploit code (if possible)
- Impact of the issue, including how an attacker might exploit it

### Response Timeline

- **Initial Response**: Within 48 hours
- **Status Update**: Within 7 days
- **Resolution Target**: Within 30 days for critical issues

### Disclosure Policy

- We will acknowledge receipt of your vulnerability report
- We will confirm the vulnerability and determine its impact
- We will release a fix and publicly acknowledge your responsible disclosure
  (unless you prefer to remain anonymous)

## Security Best Practices

When using this extension:

1. **API Key Security**: API keys are securely managed by the nr-llm extension
   on the server side. No API keys are exposed to the frontend.
2. **Access Control**: Restrict backend access to trusted editors only
3. **Regular Updates**: Keep the extension, nr-llm, and TYPO3 core updated
4. **CSP Configuration**: Review Content Security Policy settings for your
   environment
5. **XSS Protection**: All AI-generated content is HTML-escaped server-side
