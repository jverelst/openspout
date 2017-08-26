<?php

namespace Box\Spout\Reader\ODS\Creator;

use Box\Spout\Reader\ODS\Helper\CellValueFormatter;
use Box\Spout\Reader\ODS\Helper\SettingsHelper;


/**
 * Class EntityFactory
 * Factory to create helpers
 *
 * @package Box\Spout\Reader\ODS\Creator
 */
class HelperFactory extends \Box\Spout\Common\Creator\HelperFactory
{
    /**
     * @param bool $shouldFormatDates Whether date/time values should be returned as PHP objects or be formatted as strings
     * @return CellValueFormatter
     */
    public function createCellValueFormatter($shouldFormatDates)
    {
        $escaper = $this->createStringsEscaper();
        return new CellValueFormatter($shouldFormatDates, $escaper);
    }

    /**
     * @return SettingsHelper
     */
    public function createSettingsHelper()
    {
        return new SettingsHelper();
    }

    /**
     * @return \Box\Spout\Common\Helper\Escaper\ODS
     */
    public function createStringsEscaper()
    {
        /** @noinspection PhpUnnecessaryFullyQualifiedNameInspection */
        return new \Box\Spout\Common\Helper\Escaper\ODS();
    }
}