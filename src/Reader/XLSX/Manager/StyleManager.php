<?php

declare(strict_types=1);

namespace OpenSpout\Reader\XLSX\Manager;

use DOMElement;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\Wrapper\XMLReader;

class StyleManager implements StyleManagerInterface
{
    /**
     * Nodes used to find relevant information in the styles XML file.
     */
    public const XML_NODE_NUM_FMTS = 'numFmts';
    public const XML_NODE_NUM_FMT = 'numFmt';
    public const XML_NODE_CELL_XFS = 'cellXfs';
    public const XML_NODE_XF = 'xf';
    public const XML_NODE_FONTS = 'fonts';
    public const XML_NODE_FONT = 'font';
    public const XML_NODE_FILLS = 'fills';
    public const XML_NODE_FILL = 'fill';
    public const XML_NODE_BORDERS = 'borders';
    public const XML_NODE_BORDER = 'border';

    /**
     * Attributes used to find relevant information in the styles XML file.
     */
    public const XML_ATTRIBUTE_COUNT = 'count';
    public const XML_ATTRIBUTE_NUM_FMT_ID = 'numFmtId';
    public const XML_ATTRIBUTE_FORMAT_CODE = 'formatCode';
    public const XML_ATTRIBUTE_APPLY_NUMBER_FORMAT = 'applyNumberFormat';

    public const XML_ATTRIBUTE_FONT_ID = 'fontId';
    public const XML_ATTRIBUTE_APPLY_FONT = 'applyFont';
    public const XML_ATTRIBUTE_FILL_ID = 'fillId';
    public const XML_ATTRIBUTE_APPLY_FILL = 'applyFill';
    public const XML_ATTRIBUTE_BORDER_ID = 'borderId';
    public const XML_ATTRIBUTE_APPLY_BORDER = 'applyBorder';

    /**
     * By convention, default style ID is 0.
     */
    public const DEFAULT_STYLE_ID = 0;

    public const NUMBER_FORMAT_GENERAL = 'General';

    /**
     * Mapping between built-in numFmtId and the associated format - for dates only.
     *
     * @see https://msdn.microsoft.com/en-us/library/ff529597(v=office.12).aspx
     */
    private const builtinNumFmtIdToNumFormatMapping = [
        14 => 'm/d/yyyy', // @NOTE: ECMA spec is 'mm-dd-yy'
        15 => 'd-mmm-yy',
        16 => 'd-mmm',
        17 => 'mmm-yy',
        18 => 'h:mm AM/PM',
        19 => 'h:mm:ss AM/PM',
        20 => 'h:mm',
        21 => 'h:mm:ss',
        22 => 'm/d/yyyy h:mm', // @NOTE: ECMA spec is 'm/d/yy h:mm',
        45 => 'mm:ss',
        46 => '[h]:mm:ss',
        47 => 'mm:ss.0',  // @NOTE: ECMA spec is 'mmss.0',
    ];

    /** @var string Path of the XLSX file being read */
    private string $filePath;

    /** @var null|string Path of the styles XML file */
    private ?string $stylesXMLFilePath;

    /** @var array<int, string> Array containing a mapping NUM_FMT_ID => FORMAT_CODE */
    private array $customNumberFormats;

    /** @var array<array-key, array<string, null|bool|int>> Array containing a mapping STYLE_ID => [STYLE_ATTRIBUTES] */
    private array $stylesAttributes;

    /** @var array<int, bool> Cache containing a mapping NUM_FMT_ID => IS_DATE_FORMAT. Used to avoid lots of recalculations */
    private array $numFmtIdToIsDateFormatCache = [];

    /** @var array<int, array<array-key, null|bool|int|string>>, Array containing all registered fonts */
    private array $fonts = [];

    /** @var array<int, null|string> The fills that are defined in the style */
    private array $fills = [];

    // private array $borders = [];

    /** @var array<Style> The list of registered styles */
    private array $stylesArray;

    /**
     * @param string  $filePath          Path of the XLSX file being read
     * @param ?string $stylesXMLFilePath
     */
    public function __construct(string $filePath, ?string $stylesXMLFilePath)
    {
        $this->filePath = $filePath;
        $this->stylesXMLFilePath = $stylesXMLFilePath;
    }

    public function shouldFormatNumericValueAsDate(int $styleId): bool
    {
        if (null === $this->stylesXMLFilePath) {
            return false;
        }

        $stylesAttributes = $this->getStylesAttributes();

        // Default style (0) does not format numeric values as timestamps. Only custom styles do.
        // Also if the style ID does not exist in the styles.xml file, format as numeric value.
        // Using isset here because it is way faster than array_key_exists...
        if (self::DEFAULT_STYLE_ID === $styleId || !isset($stylesAttributes[$styleId])) {
            return false;
        }

        $styleAttributes = $stylesAttributes[$styleId];

        return $this->doesStyleIndicateDate($styleAttributes);
    }

    public function getNumberFormatCode(int $styleId): string
    {
        $stylesAttributes = $this->getStylesAttributes();
        $styleAttributes = $stylesAttributes[$styleId];
        $numFmtId = $styleAttributes[self::XML_ATTRIBUTE_NUM_FMT_ID];
        \assert(\is_int($numFmtId));

        if ($this->isNumFmtIdBuiltInDateFormat($numFmtId)) {
            $numberFormatCode = self::builtinNumFmtIdToNumFormatMapping[$numFmtId];
        } else {
            $customNumberFormats = $this->getCustomNumberFormats();
            $numberFormatCode = $customNumberFormats[$numFmtId];
        }

        return $numberFormatCode;
    }

    public function getStyleById(int $id): Style
    {
        if (!isset($this->stylesArray)) {
            $this->extractRelevantInfo();
        }

        return $this->stylesArray[$id];
    }

    /**
     * @return array<int, string> The custom number formats
     */
    protected function getCustomNumberFormats(): array
    {
        if (!isset($this->customNumberFormats)) {
            $this->extractRelevantInfo();
        }

        return $this->customNumberFormats;
    }

    /**
     * @return array<array-key, array<string, null|bool|int>> The styles attributes
     */
    protected function getStylesAttributes(): array
    {
        if (!isset($this->stylesAttributes)) {
            $this->extractRelevantInfo();
        }

        return $this->stylesAttributes;
    }

    /**
     * Reads the styles.xml file and extract the relevant information from the file.
     */
    private function extractRelevantInfo(): void
    {
        $this->customNumberFormats = [];
        $this->stylesAttributes = [];
        $this->stylesArray = [];

        $xmlReader = new XMLReader();

        if ($xmlReader->openFileInZip($this->filePath, $this->stylesXMLFilePath)) {
            while ($xmlReader->read()) {
                if ($xmlReader->isPositionedOnStartingNode(self::XML_NODE_FONTS)) {
                    if ($xmlReader->getAttribute(self::XML_ATTRIBUTE_COUNT) != "0") {
                        $this->extractFonts($xmlReader);
                    }
                } elseif ($xmlReader->isPositionedOnStartingNode(self::XML_NODE_FILLS)) {
                    if ($xmlReader->getAttribute(self::XML_ATTRIBUTE_COUNT) != "0") {
                        $this->extractFills($xmlReader);
                    }
                } elseif ($xmlReader->isPositionedOnStartingNode(self::XML_NODE_BORDERS)) {
                    if ($xmlReader->getAttribute(self::XML_ATTRIBUTE_COUNT) != "0") {
                        $this->extractBorders($xmlReader);
                    }
                } elseif ($xmlReader->isPositionedOnStartingNode(self::XML_NODE_NUM_FMTS)) {
                    if ($xmlReader->getAttribute(self::XML_ATTRIBUTE_COUNT) != "0") {
                        $this->extractNumberFormats($xmlReader);
                    }
                } elseif ($xmlReader->isPositionedOnStartingNode(self::XML_NODE_CELL_XFS)) {
                    if ($xmlReader->getAttribute(self::XML_ATTRIBUTE_COUNT) != "0") {
                        $this->extractStyleAttributes($xmlReader);
                    }
                }
            }

            $xmlReader->close();
        }
    }

    /**
     * Extracts number formats from the "numFmt" nodes.
     * For simplicity, the styles attributes are kept in memory. This is possible thanks
     * to the reuse of formats. So 1 million cells should not use 1 million formats.
     *
     * @param \OpenSpout\Reader\Wrapper\XMLReader $xmlReader XML Reader positioned on the "numFmts" node
     */
    private function extractNumberFormats(XMLReader $xmlReader): void
    {
        while ($xmlReader->read()) {
            if ($xmlReader->isPositionedOnStartingNode(self::XML_NODE_NUM_FMT)) {
                $numFmtId = (int) $xmlReader->getAttribute(self::XML_ATTRIBUTE_NUM_FMT_ID);
                $formatCode = $xmlReader->getAttribute(self::XML_ATTRIBUTE_FORMAT_CODE);
                \assert(null !== $formatCode);
                $this->customNumberFormats[$numFmtId] = $formatCode;
            } elseif ($xmlReader->isPositionedOnEndingNode(self::XML_NODE_NUM_FMTS)) {
                // Once done reading "numFmts" node's children
                break;
            }
        }
    }

    /**
     * Extracts font formats.
     * For simplicity, the styles attributes are kept in memory. This is possible thanks
     * to the reuse of formats. So 1 million cells should not use 1 million formats.
     *
     * @param \OpenSpout\Reader\Wrapper\XMLReader $xmlReader XML Reader positioned on the "numFmts" node
     */
    private function extractFonts(XMLReader $xmlReader): void
    {
        while ($xmlReader->read()) {
            if ($xmlReader->isPositionedOnStartingNode(self::XML_NODE_FONT)) {
                $fontNode = $xmlReader->expand();
                \assert($fontNode instanceof DOMElement);

                $sizeNode = $fontNode->getElementsByTagName('sz');
                $colorNode = $fontNode->getElementsByTagName('color');
                $nameNode = $fontNode->getElementsByTagName('name');
                $familyNode = $fontNode->getElementsByTagName('family');
                $boldNode = $fontNode->getElementsByTagName('b');
                $italicNode = $fontNode->getElementsByTagName('i');
                $underlineNode = $fontNode->getElementsByTagName('u');
                $strikeNode = $fontNode->getElementsByTagName('strike');

                $size = 1 === $sizeNode->count() ? $sizeNode[0]->getAttribute('val') : '12';
                $color = 1 === $colorNode->count() ? $colorNode[0]->getAttribute('rgb') : 'FF000000';
                $family = 1 === $familyNode->count() ? $familyNode[0]->getAttribute('val') : '2';
                $name = 1 === $nameNode->count() ? $nameNode[0]->getAttribute('val') : 'Arial';

                $italic = 1 === $italicNode->count();
                $bold = 1 === $boldNode->count();
                $underline = 1 === $underlineNode->count();
                $strike = 1 === $strikeNode->count();

                $this->fonts[] = [
                    'name' => $name,
                    'family' => $family,
                    'size' => (int) $size,
                    'color' => $color,
                    'italic' => $italic,
                    'bold' => $bold,
                    'underline' => $underline,
                    'strike' => $strike,
                ];
            } elseif ($xmlReader->isPositionedOnEndingNode(self::XML_NODE_FONTS)) {
                // Once done reading "fonts" node's children
                break;
            }
        }
    }

    /**
     * Extracts fills.
     * For simplicity, the styles attributes are kept in memory. This is possible thanks
     * to the reuse of formats. So 1 million cells should not use 1 million formats.
     *
     * @param \OpenSpout\Reader\Wrapper\XMLReader $xmlReader XML Reader positioned on the "fills" node
     */
    private function extractFills(XMLReader $xmlReader): void
    {
        while ($xmlReader->read()) {
            if ($xmlReader->isPositionedOnStartingNode(self::XML_NODE_FILL)) {
                $fillNode = $xmlReader->expand();
                \assert($fillNode instanceof DOMElement);

                $patternFills = $fillNode->getElementsByTagName('patternFill');
                \assert(1 === $patternFills->count());

                $pattern = $patternFills[0];
                $type = $pattern->getAttribute('patternType');

                if ('solid' === $type) {
                    $fgNode = $pattern->getElementsByTagName('fgColor')[0];
                    if ($fgNode != null) {
                        $color = $fgNode->getAttribute('rgb');
                        if ($color !== "") {
                            $this->fills[] = $color;
                        } else {
                            $this->fills[] = null;
                        }
                    } else {
                        $this->fills[] = null;
                    }
                } else {
                    $this->fills[] = null;
                }
            } elseif ($xmlReader->isPositionedOnEndingNode(self::XML_NODE_FILLS)) {
                // Once done reading "fills" node's children
                break;
            }
        }
    }

    /**
     * Extracts borders.
     * For simplicity, the styles attributes are kept in memory. This is possible thanks
     * to the reuse of formats. So 1 million cells should not use 1 million formats.
     *
     * @param \OpenSpout\Reader\Wrapper\XMLReader $xmlReader XML Reader positioned on the "numFmts" node
     */
    private function extractBorders(XMLReader $xmlReader): void
    {
    }

    /**
     * Extracts style attributes from the "xf" nodes, inside the "cellXfs" section.
     * For simplicity, the styles attributes are kept in memory. This is possible thanks
     * to the reuse of styles. So 1 million cells should not use 1 million styles.
     *
     * @param \OpenSpout\Reader\Wrapper\XMLReader $xmlReader XML Reader positioned on the "cellXfs" node
     */
    private function extractStyleAttributes(XMLReader $xmlReader): void
    {
        while ($xmlReader->read()) {
            if ($xmlReader->isPositionedOnStartingNode(self::XML_NODE_XF)) {
                $numFmtId = $xmlReader->getAttribute(self::XML_ATTRIBUTE_NUM_FMT_ID);
                $normalizedNumFmtId = (null !== $numFmtId) ? (int) $numFmtId : null;

                $applyNumberFormat = $xmlReader->getAttribute(self::XML_ATTRIBUTE_APPLY_NUMBER_FORMAT);
                $normalizedApplyNumberFormat = (null !== $applyNumberFormat) ? (bool) $applyNumberFormat : null;

                $applyFont = $xmlReader->getAttribute(self::XML_ATTRIBUTE_APPLY_FONT);
                $fontId = $xmlReader->getAttribute(self::XML_ATTRIBUTE_FONT_ID);

                $applyFill = $xmlReader->getAttribute(self::XML_ATTRIBUTE_APPLY_FILL);
                $fillId = $xmlReader->getAttribute(self::XML_ATTRIBUTE_FILL_ID);

                $applyBorder = $xmlReader->getAttribute(self::XML_ATTRIBUTE_APPLY_BORDER);
                $borderId = $xmlReader->getAttribute(self::XML_ATTRIBUTE_BORDER_ID);

                $this->stylesAttributes[] = [
                    self::XML_ATTRIBUTE_NUM_FMT_ID => $normalizedNumFmtId,
                    self::XML_ATTRIBUTE_APPLY_NUMBER_FORMAT => $normalizedApplyNumberFormat,
                ];

                $style = new Style();
                if ('1' === $applyFont) {
                    $font = $this->fonts[(int) $fontId];
                    $style->setFontSize((int) $font['size']);
                    $style->setFontName((string) $font['name']);
                    $style->setFontColor((string) $font['color']);
                    if ((bool) $font['italic']) {
                        $style->setFontItalic();
                    }
                    if ((bool) $font['bold']) {
                        $style->setFontBold();
                    }
                    if ((bool) $font['underline']) {
                        $style->setFontUnderline();
                    }
                    if ((bool) $font['strike']) {
                        $style->setFontStrikethrough();
                    }
                }

                if ('1' === $applyFill) {
                    $fill = $this->fills[(int) $fillId];
                    if (null !== $fill) {
                        $style->setBackgroundColor($fill);
                    }
                }

                if (null !== $normalizedNumFmtId) {
                    $formatCode = $this->getFormatCodeForNumFmtId($normalizedNumFmtId);
                    if (null !== $formatCode) {
                        $style->setFormat($this->getFormatCodeForNumFmtId($normalizedNumFmtId));
                    }
                }

                $this->stylesArray[] = $style;
            } elseif ($xmlReader->isPositionedOnEndingNode(self::XML_NODE_CELL_XFS)) {
                // Once done reading "cellXfs" node's children
                break;
            }
        }
    }

    /**
     * @param array<string, null|bool|int> $styleAttributes Array containing the style attributes (2 keys: "applyNumberFormat" and "numFmtId")
     *
     * @return bool Whether the style with the given attributes indicates that the number is a date
     */
    private function doesStyleIndicateDate(array $styleAttributes): bool
    {
        $applyNumberFormat = $styleAttributes[self::XML_ATTRIBUTE_APPLY_NUMBER_FORMAT];
        $numFmtId = $styleAttributes[self::XML_ATTRIBUTE_NUM_FMT_ID];

        // A style may apply a date format if it has:
        //  - "applyNumberFormat" attribute not set to "false"
        //  - "numFmtId" attribute set
        // This is a preliminary check, as having "numFmtId" set just means the style should apply a specific number format,
        // but this is not necessarily a date.
        if (false === $applyNumberFormat || !\is_int($numFmtId)) {
            return false;
        }

        return $this->doesNumFmtIdIndicateDate($numFmtId);
    }

    /**
     * Returns whether the number format ID indicates that the number is a date.
     * The result is cached to avoid recomputing the same thing over and over, as
     * "numFmtId" attributes can be shared between multiple styles.
     *
     * @return bool Whether the number format ID indicates that the number is a date
     */
    private function doesNumFmtIdIndicateDate(int $numFmtId): bool
    {
        if (!isset($this->numFmtIdToIsDateFormatCache[$numFmtId])) {
            $formatCode = $this->getFormatCodeForNumFmtId($numFmtId);

            $this->numFmtIdToIsDateFormatCache[$numFmtId] = (
                $this->isNumFmtIdBuiltInDateFormat($numFmtId)
                || $this->isFormatCodeCustomDateFormat($formatCode)
            );
        }

        return $this->numFmtIdToIsDateFormatCache[$numFmtId];
    }

    /**
     * @return null|string The custom number format or NULL if none defined for the given numFmtId
     */
    private function getFormatCodeForNumFmtId(int $numFmtId): ?string
    {
        $customNumberFormats = $this->getCustomNumberFormats();

        // Using isset here because it is way faster than array_key_exists...
        return (isset($customNumberFormats[$numFmtId])) ? $customNumberFormats[$numFmtId] : null;
    }

    /**
     * @return bool Whether the number format ID indicates that the number is a date
     */
    private function isNumFmtIdBuiltInDateFormat(int $numFmtId): bool
    {
        return \array_key_exists($numFmtId, self::builtinNumFmtIdToNumFormatMapping);
    }

    /**
     * @return bool Whether the given format code indicates that the number is a date
     */
    private function isFormatCodeCustomDateFormat(?string $formatCode): bool
    {
        // if no associated format code or if using the default "General" format
        if (null === $formatCode || 0 === strcasecmp($formatCode, self::NUMBER_FORMAT_GENERAL)) {
            return false;
        }

        return $this->isFormatCodeMatchingDateFormatPattern($formatCode);
    }

    /**
     * @return bool Whether the given format code matches a date format pattern
     */
    private function isFormatCodeMatchingDateFormatPattern(string $formatCode): bool
    {
        // Remove extra formatting (what's between [ ], the brackets should not be preceded by a "\")
        $pattern = '((?<!\\\)\[.+?(?<!\\\)\])';
        $formatCode = preg_replace($pattern, '', $formatCode);
        \assert(null !== $formatCode);

        // custom date formats contain specific characters to represent the date:
        // e - yy - m - d - h - s
        // and all of their variants (yyyy - mm - dd...)
        $dateFormatCharacters = ['e', 'yy', 'm', 'd', 'h', 's'];

        $hasFoundDateFormatCharacter = false;
        foreach ($dateFormatCharacters as $dateFormatCharacter) {
            // character not preceded by "\" (case insensitive)
            $pattern = '/(?<!\\\)'.$dateFormatCharacter.'/i';

            if (1 === preg_match($pattern, $formatCode)) {
                $hasFoundDateFormatCharacter = true;

                break;
            }
        }

        return $hasFoundDateFormatCharacter;
    }
}
