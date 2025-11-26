# Demo Deployment Information

## Current Demo Status

The demo installation at https://t3ai.surge.sh/ is currently using **discontinued OpenAI language models**.

## Issue

The demo is using:
- Deprecated model: `gpt-3.5-turbo-instruct`
- Deprecated endpoint: `/v1/completions`

These have been replaced by:
- Current models: `gpt-4`, `gpt-4-turbo`, `gpt-3.5-turbo` (chat models)
- Current endpoint: `/v1/chat/completions`

## Deployment Information

### No Deployment Configuration in This Repository

This repository (t3x-cowriter) **does not contain** any deployment configuration or documentation for updating https://t3ai.surge.sh/.

- No surge.sh configuration files
- No GitHub workflows for surge deployment
- No deployment scripts or instructions

### Related Repositories

The demo appears to use the CKEditor plugin from the separate repository:
- Repository: https://github.com/netresearch/ckeditor-cowriter
- Demo (GitHub Pages): https://netresearch.github.io/ckeditor-cowriter/

The ckeditor-cowriter repository has:
- A GitHub Pages deployment workflow
- Updated models and API endpoints
- Proper GitHub Actions setup for automated deployments

## Recommendations

To update the https://t3ai.surge.sh/ demo:

1. **Access Required**: Someone with access to the surge.sh deployment credentials for the `t3ai` project needs to update it.

2. **Update Process** (for whoever has surge.sh access):
   ```bash
   # Install surge CLI
   npm install -g surge
   
   # Login to surge (requires credentials)
   surge login
   
   # Deploy updated demo files
   surge ./path/to/demo/files t3ai.surge.sh
   ```

3. **Alternative Solution**: Consider redirecting t3ai.surge.sh to the maintained GitHub Pages demo at https://netresearch.github.io/ckeditor-cowriter/, or updating the README.md to point to the GitHub Pages demo instead.

4. **Update Required Files**: The demo would need updated versions of:
   - The cowriter plugin files (using new API endpoints)
   - Updated model references (using chat models instead of completion models)
   - Valid OpenAI API credentials

## Who Can Update?

The person or team with:
- Surge.sh account access for the `t3ai` domain
- OpenAI API credentials to embed in the demo
- Or: Repository maintainer permissions to update the README

## Next Steps

Since this repository does not contain deployment configuration for surge.sh:
1. Contact @olagwin (mentioned in the issue) who may have surge.sh access
2. Either update the surge.sh deployment or
3. Update the README.md to reference the GitHub Pages demo instead
