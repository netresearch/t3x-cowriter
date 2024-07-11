<?php
namespace Netresearch\T3Cowriter\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;


/**
 * Model representing a content element with a table and field.
 *
 * @package Netresearch\SuperAdmin
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license https://www.gnu.org/licenses/gpl-3.0.de.html GPL-3.0-or-later
 */
class ContentElement extends AbstractEntity {
    /**
     * @var string
     */
    protected string $table = '';

    /**
     * @var string
     */
    protected string $field = '';

    /**
     *
     * Gets the table name.
     *
     * @return string
     */
    public function getTable() {
        return $this->table;
    }

    /**
     *
     * Sets the table name.
     *
     * @param $table
     * @return void
     */
    public function setTable($table) {
        $this->table = $table;
    }

    /**
     *
     * Gets the field name.
     *
     * @return string
     */
    public function getField() {
        return $this->field;
    }

    /**
     *
     * Sets the field name.
     *
     * @param $field
     * @return void
     */
    public function setPrompt($field) {
        $this->field = $field;
    }
}
