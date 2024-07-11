<?php
namespace Netresearch\T3Cowriter\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Definition of the Prompt class that extends AbstractEntity
 *
 * @package Netresearch\T3Cowriter
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license https://www.gnu.org/licenses/gpl-3.0.de.html GPL-3.0-or-later
 */
class Prompt extends AbstractEntity {

    /**
     * @var string
     */
    protected string $title = '';

    /**
     * @var string
     */
    protected string $prompt = '';

    /**
     *
     * Gets the title of the prompt.
     *
     * @return string
     */
    public function getTitle() {
        return $this->title;
    }

    /**
     *
     * Sets the title of the prompt.
     *
     * @param string $title
     */
    public function setTitle($title) {
        $this->title = $title;
    }

    /**
     *
     * Gets the content of the prompt.
     *
     * @return string
     */
    public function getPrompt() {
        return $this->prompt;
    }

    /**
     *
     * Sets the content of the prompt.
     *
     * @param string $prompt
     */
    public function setPrompt($prompt) {
        $this->prompt = $prompt;
    }
}