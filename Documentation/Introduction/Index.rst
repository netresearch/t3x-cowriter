..  include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

What does it do?
================

The AI Cowriter extension integrates multiple LLM providers directly into the
TYPO3 CKEditor, allowing editors to generate content with the help of artificial
intelligence. Using the
:ref:`nr-llm extension <nrllm:start>`, it supports OpenAI,
Anthropic Claude, Google Gemini, OpenRouter, Mistral, and Groq.

Did you ever wish to have a second person to work on a TYPO3 page together with
you? This extension allows you to do exactly that. With the help of AI you can
now work on a page together with a cowriter - a digital assistant that helps you
to write your content.

..  figure:: /Images/CowriterDialog.png
    :alt: Cowriter task dialog in TYPO3 backend
    :class: with-border with-shadow

    The Cowriter dialog with task selection, context scope control,
    and additional instructions.

Features
========

*   **Task-based dialog**: Select from predefined tasks (Improve, Summarize,
    Extend, Fix Grammar, Translate) with result preview before inserting
*   **Inline translation**: Translate selected text directly from a toolbar
    dropdown — supports 10 languages (German, English, French, Spanish,
    Italian, Dutch, Portuguese, Polish, Japanese, Chinese)
*   **Alt text generation**: Generate image alt text via LLM vision analysis
*   **Prompt templates**: Apply prompt template presets from the toolbar
*   **CKEditor Integration**: Four toolbar buttons for writing, translation,
    vision, and templates
*   **Multi-Provider Support**: Works with all LLM providers supported by
    :ref:`nr-llm <nrllm:start>` (OpenAI, Claude, Gemini,
    OpenRouter, Mistral, Groq)
*   **Secure Backend Proxy**: API keys never exposed to frontend — all
    requests proxied through TYPO3 backend
*   **Context control**: Choose between selected text or full content element
*   **Ad-hoc instructions**: Add custom instructions per request
*   **Rate limiting**: 20 requests/minute per backend user
*   **Streaming**: Server-Sent Events for real-time completions
*   **XSS Protection**: All LLM output is HTML-escaped for defense in depth
*   Support for TYPO3 v13.4 and v14
*   Compatible with PHP 8.2 — 8.5

Requirements
============

*   TYPO3 v13.4 or v14
*   PHP 8.2 or higher
*   :ref:`nr-llm extension <nrllm:start>` (for LLM provider configuration)
*   CKEditor (rte_ckeditor) extension
