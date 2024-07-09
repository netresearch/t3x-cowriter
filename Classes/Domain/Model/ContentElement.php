<?php
namespace Netresearch\T3Cowriter\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class ContentElement extends AbstractEntity {
    /**
     * @var string
     */
    protected $table = '';

    /**
     * @var string
     */
    protected $field = '';

    // Getter und Setter Methoden

    public function getTable() {
        return $this->table;
    }

    public function setTable($table) {
        $this->table = $table;
    }

    public function getField() {
        return $this->field;
    }

    public function setPrompt($field) {
        $this->field = $field;
    }
}
