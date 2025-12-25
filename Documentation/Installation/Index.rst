..  include:: /Includes.rst.txt

.. _installation:

============
Installation
============

The extension is installed via Composer only.

..  note::

    The extension is currently not available via TER (TYPO3 Extension Repository).

Composer installation
=====================

Require the extension in your TYPO3 project:

..  code-block:: bash

    composer require netresearch/t3-cowriter

After installation, activate the extension in the TYPO3 Extension Manager or via CLI:

..  code-block:: bash

    vendor/bin/typo3 extension:activate t3_cowriter

Version matrix
==============

==============  ==============  ================
Extension       TYPO3           PHP
==============  ==============  ================
2.x             12.4 - 13.4     8.2 - 8.4
1.x             11.5            7.4 - 8.1
==============  ==============  ================
