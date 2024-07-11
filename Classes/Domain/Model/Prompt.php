<?php
namespace netresearch\t3_cowriter\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

// Definition of the Prompt class that extends AbstractEntity
class Prompt extends AbstractEntity {
    /**
     * @var string
     */
    protected $title = '';

    /**
     * @var string
     */
    protected $prompt = '';

    /**
     * @return string
     */
    public function getTitle() {
        return $this->title;
    }

    /**
     * @param string $title
     */
    public function setTitle($title) {
        $this->title = $title;
    }

    /**
     * @return string
     */
    public function getPrompt() {
        return $this->prompt;
    }

    /**
     * @param string $prompt
     */
    public function setPrompt($prompt) {
        $this->prompt = $prompt;
    }
}