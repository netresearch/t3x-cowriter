<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Model representing a content element with a table and field.
 *
 * @author  Philipp Altmann <philipp.altmann@netresearch.de>
 * @license https://www.gnu.org/licenses/gpl-3.0.de.html GPL-3.0-or-later
 */
class ContentElement extends AbstractEntity
{
    /**
     * @var string
     */
    protected string $title = '';

    /**
     * @var string
     */
    protected string $table = '';

    /**
     * @var string
     */
    protected string $field = '';

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @return void
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * @return string
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * @param $table
     *
     * @return void
     */
    public function setTable($table)
    {
        $this->table = $table;
    }

    /**
     * @return string
     */
    public function getField()
    {
        return $this->field;
    }

    /**
     * @param $field
     *
     * @return void
     */
    public function setField($field)
    {
        $this->field = $field;
    }
}
