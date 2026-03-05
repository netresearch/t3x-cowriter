..  include:: /Includes.rst.txt

.. _api:

=============
API Reference
=============

The Cowriter extension exposes several AJAX endpoints under
``/typo3/ajax/cowriter/``. All endpoints require an authenticated
backend session.

.. contents::
   :local:

Common response format
======================

All endpoints return JSON with a ``success`` boolean. On error,
an ``error`` string describes the problem.

Rate limit headers are included on every response:

*   ``X-RateLimit-Limit`` — maximum requests per window
*   ``X-RateLimit-Remaining`` — requests remaining in current window
*   ``Retry-After`` — seconds until reset (only on HTTP 429)

Completion endpoints
====================

.. _api-complete:

POST /cowriter/complete
-----------------------

Generate a completion from a prompt using the default or specified
LLM configuration.

**Request body:**

.. code-block:: json

    {
        "prompt": "Write an introduction about TYPO3",
        "configuration": "openai-default"
    }

**Response (200):**

.. code-block:: json

    {
        "success": true,
        "content": "TYPO3 is a powerful...",
        "model": "gpt-5.2",
        "finishReason": "stop",
        "wasTruncated": false,
        "wasFiltered": false,
        "usage": {
            "promptTokens": 50,
            "completionTokens": 120,
            "totalTokens": 170
        }
    }

.. _api-stream:

POST /cowriter/stream
---------------------

Stream a completion via Server-Sent Events (SSE). Same request format
as ``/cowriter/complete``.

Returns ``text/event-stream`` with JSON chunks:

.. code-block:: text

    data: {"content": "TYPO3 ", "done": false}
    data: {"content": "is a ", "done": false}
    data: {"content": "powerful...", "done": true, "model": "gpt-5.2"}

.. _api-task-execute:

POST /cowriter/task-execute
---------------------------

Execute a predefined task with context assembly.

**Request body:**

.. code-block:: json

    {
        "taskIdentifier": "improve-text",
        "instruction": "Improve this text",
        "context": "<p>Current editor content</p>",
        "contextScope": "content_element",
        "configuration": "openai-default",
        "referencePages": [
            {"pid": 42, "relation": "style guide"}
        ]
    }

Translation
===========

.. _api-translate:

POST /cowriter/translate
------------------------

Translate text to a target language.

**Request body:**

.. code-block:: json

    {
        "text": "Hello world",
        "targetLanguage": "de",
        "formality": "formal",
        "domain": "technical",
        "configuration": "claude-fast"
    }

``formality`` defaults to ``"default"``, ``domain`` defaults to
``"general"``. ``configuration`` is optional.

**Response (200):**

.. code-block:: json

    {
        "success": true,
        "translation": "Hallo Welt",
        "sourceLanguage": "en",
        "confidence": 0.95,
        "usage": {
            "promptTokens": 30,
            "completionTokens": 10,
            "totalTokens": 40
        }
    }

Vision / Alt text
=================

.. _api-vision:

POST /cowriter/vision
---------------------

Analyze an image and generate descriptive alt text.

**Request body:**

.. code-block:: json

    {
        "imageUrl": "https://example.com/photo.jpg",
        "prompt": "Generate a concise, descriptive alt text for this image."
    }

The ``prompt`` defaults to a standard alt text generation prompt
if omitted.

**Response (200):**

.. code-block:: json

    {
        "success": true,
        "altText": "A red bicycle parked against a brick wall",
        "model": "gpt-5.2",
        "confidence": 0.92,
        "usage": {
            "promptTokens": 200,
            "completionTokens": 15,
            "totalTokens": 215
        }
    }

Templates
=========

.. _api-templates:

GET /cowriter/templates
-----------------------

List available prompt templates (tasks with ``category = 'content'``).

**Response (200):**

.. code-block:: json

    {
        "success": true,
        "templates": [
            {
                "identifier": "improve-text",
                "name": "Improve Text",
                "description": "Enhance readability and quality",
                "category": "content"
            }
        ]
    }

Tool calling
============

.. _api-tools:

POST /cowriter/tools
--------------------

Execute an LLM request with tool calling capabilities.

**Request body:**

.. code-block:: json

    {
        "prompt": "Find all headings in the content",
        "tools": ["query_content"]
    }

``tools`` is an optional array of tool names to enable. If omitted,
all available tools are enabled.

**Response (200):**

.. code-block:: json

    {
        "success": true,
        "content": "I found 3 headings...",
        "toolCalls": [],
        "finishReason": "stop",
        "usage": {
            "promptTokens": 100,
            "completionTokens": 50,
            "totalTokens": 150
        }
    }

Configurations
==============

.. _api-configurations:

GET /cowriter/configurations
----------------------------

List active LLM configurations available for selection.

**Response (200):**

.. code-block:: json

    {
        "success": true,
        "configurations": [
            {
                "identifier": "openai-default",
                "name": "OpenAI GPT-5.2",
                "isDefault": true
            }
        ]
    }

Error responses
===============

All endpoints use standard HTTP status codes:

*   **400** — Invalid JSON, missing required fields, or fields exceeding
    maximum length (32 KB)
*   **429** — Rate limit exceeded (includes ``Retry-After`` header)
*   **500** — LLM service error

.. code-block:: json

    {
        "success": false,
        "error": "Missing or empty text parameter."
    }
