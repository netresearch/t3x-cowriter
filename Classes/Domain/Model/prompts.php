<?php
namespace netresearch\t3_cowriter\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Prompts extends AbstractEntity {
    /**
     * @var string
     */
    protected $title = '';

    /**
     * @var string
     */
    protected $prompt = '';

    // Getter und Setter Methoden

    public function getTitle() {
        return $this->title;
    }

    public function setTitle($title) {
        $this->title = $title;
    }

    public function getPrompt() {
        return $this->prompt;
    }

    public function setPrompt($prompt) {
        $this->prompt = $prompt;
    }
}