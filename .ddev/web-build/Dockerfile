# Custom Dockerfile for t3_cowriter TYPO3 extension development
# Adds PHP extensions and tools needed for development

# Install PCOV for fast code coverage using apt-get (pecl not available in DDEV)
RUN apt-get update && apt-get install -y php${PHP_VERSION}-pcov && rm -rf /var/lib/apt/lists/*

# Upgrade PHP to latest patch version (get fixes before DDEV updates)
# Use dist-upgrade to handle dependency changes between versions
RUN apt-get update && \
    apt-get dist-upgrade -y && \
    rm -rf /var/lib/apt/lists/*
